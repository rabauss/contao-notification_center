<?php

/**
 * notification_center extension for Contao Open Source CMS
 *
 * @copyright  Copyright (c) 2008-2018, terminal42
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    LGPL
 */

namespace Contao;

use NotificationCenter\Model\Notification;

/**
 * Front end module "newsletter subscribe".
 *
 * @property string $nl_subscribe
 * @property array  $nl_channels
 * @property string $nl_template
 * @property string $nl_text
 * @property bool   $nl_hideChannels
 * @property int    $nc_notification
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleNewsletterSubscribeNotificationCenter extends ModuleSubscribe
{
    /**
     * Generate the module
     */
    protected function compile()
    {
        $this->setCustomTemplate();

        $objCaptchaWidget = $this->createCaptchaWidgetIfEnabled();

        $strFormId = 'tl_subscribe_' . $this->id;

        $this->processForm($strFormId, $objCaptchaWidget);
        $this->compileConfirmationMessage();

        $this->Template->email = '';
        $this->Template->captcha = $objCaptchaWidget ? $objCaptchaWidget->parse() : '';
        $this->Template->channels = $this->compileChannels();
        $this->Template->showChannels = !$this->nl_hideChannels;
        $this->Template->submit = \StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['subscribe']);
        $this->Template->channelsLabel = $GLOBALS['TL_LANG']['MSC']['nl_channels'];
        $this->Template->emailLabel = $GLOBALS['TL_LANG']['MSC']['emailAddress'];
        $this->Template->action = \Environment::get('indexFreeRequest');
        $this->Template->formId = $strFormId;
        $this->Template->id = $this->id;
        $this->Template->text = $this->nl_text;
    }

    /**
     * Add a new recipient
     *
     * @param string $strEmail
     * @param array  $arrNew
     */
    protected function addRecipient($strEmail, $arrNew)
    {
        // Remove old subscriptions that have not been activated yet
        if (($objOld = \NewsletterRecipientsModel::findOldSubscriptionsByEmailAndPids($strEmail, $arrNew)) !== null)
        {
            while ($objOld->next())
            {
                $objOld->delete();
            }
        }

        $time = time();
        $strToken = md5(uniqid(mt_rand(), true));

        // Add the new subscriptions
        foreach ($arrNew as $id)
        {
            $objRecipient = new \NewsletterRecipientsModel();
            $objRecipient->pid = $id;
            $objRecipient->tstamp = $time;
            $objRecipient->email = $strEmail;
            $objRecipient->active = '';
            $objRecipient->addedOn = $time;
            $objRecipient->ip = \Environment::get('ip');
            $objRecipient->token = $strToken;
            $objRecipient->confirmed = '';
            $objRecipient->save();

            // Remove the blacklist entry (see #4999)
            if (($objBlacklist = \NewsletterBlacklistModel::findByHashAndPid(md5($strEmail), $id)) !== null)
            {
                $objBlacklist->delete();
            }
        }

        $this->sendNotification($strToken, $strEmail, $arrNew);

        // Redirect to the jumpTo page
        if ($this->jumpTo && ($objTarget = $this->objModel->getRelated('jumpTo')) instanceof PageModel)
        {
            /** @var PageModel $objTarget */
            $this->redirect($objTarget->getFrontendUrl());
        }

        \System::getContainer()->get('session')->getFlashBag()->set('nl_confirm', $GLOBALS['TL_LANG']['MSC']['nl_confirm']);

        $this->reload();
    }

    protected function setCustomTemplate()
    {
        if ($this->nl_template) {
            $this->Template = new \FrontendTemplate($this->nl_template);
            $this->Template->setData($this->arrData);
        }
    }

    /**
     * @return Widget|null
     */
    protected function createCaptchaWidgetIfEnabled()
    {
        $objWidget = null;

        // Set up the captcha widget
        if (!$this->disableCaptcha) {
            $arrField = [
                'name'      => 'subscribe_' . $this->id,
                'label'     => $GLOBALS['TL_LANG']['MSC']['securityQuestion'],
                'inputType' => 'captcha',
                'eval'      => ['mandatory' => true]
            ];

            /** @var Widget $objWidget */
            $objWidget = new \FormCaptcha(\FormCaptcha::getAttributesFromDca($arrField, $arrField['name']));
        }

        return $objWidget;
    }

    protected function compileConfirmationMessage()
    {
        $session = \System::getContainer()->get('session');
        $flashBag = $session->getFlashBag();

        // Confirmation message
        if ($session->isStarted() && $flashBag->has('nl_confirm'))
        {
            $arrMessages = $flashBag->get('nl_confirm');

            $this->Template->mclass = 'confirm';
            $this->Template->message = $arrMessages[0];
        }
    }

    private function compileChannels()
    {
        $arrChannels = array();
        $objChannel = \NewsletterChannelModel::findByIds($this->nl_channels);

        // Get the titles
        if ($objChannel !== null)
        {
            while ($objChannel->next())
            {
                $arrChannels[$objChannel->id] = $objChannel->title;
            }
        }

        return $arrChannels;
    }

    /**
     * @param $strFormId
     * @param $objCaptchaWidget
     */
    protected function processForm($strFormId, $objCaptchaWidget)
    {
        if (\Input::post('FORM_SUBMIT') == $strFormId) {
            $varSubmitted = $this->validateForm($objCaptchaWidget);

            if ($varSubmitted !== false) {
                \call_user_func_array([$this, 'addRecipient'], $varSubmitted);
            }
        }
    }

    protected function sendNotification($strToken, $strEmail, array $arrNew)
    {
        $objNotification = Notification::findByPk($this->nc_notification);
        if (!$objNotification) {
            return;
        }

        $objChannel = \NewsletterChannelModel::findByIds($arrNew);
        $arrChannels = $objChannel ? $objChannel->fetchEach('title') : [];

        // Prepare the simple token data
        $arrData = array();
        $arrData['email'] = $strEmail;
        $arrData['token'] = $strToken;
        $arrData['domain'] = \Idna::decode(\Environment::get('host'));
        $arrData['link'] = \Idna::decode(\Environment::get('base')) . \Environment::get('request') . ((strpos(\Environment::get('request'), '?') !== false) ? '&' : '?') . 'token=' . $strToken;
        $arrData['channel'] = $arrData['channels'] = implode("\n", $arrChannels);
        $arrData['admin_email'] = $GLOBALS['TL_ADMIN_EMAIL'];
        $arrData['admin_name'] = $GLOBALS['TL_ADMIN_NAME'];
        $arrData['subject'] = sprintf($GLOBALS['TL_LANG']['MSC']['nl_subject'], \Idna::decode(\Environment::get('host')));

        $objNotification->send($arrData);
    }
}
