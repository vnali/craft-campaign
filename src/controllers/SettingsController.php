<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\controllers;

use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\models\SettingsModel;
use putyourlightson\campaign\elements\ContactElement;

use Craft;
use craft\web\Controller;
use craft\helpers\ArrayHelper;
use craft\helpers\MailerHelper;
use craft\errors\MissingComponentException;
use craft\mail\transportadapters\BaseTransportAdapter;
use craft\mail\transportadapters\Sendmail;
use craft\mail\transportadapters\TransportAdapterInterface;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * SettingsController
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0   
 */
class SettingsController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @var SettingsModel $_settings
     */
    private $_settings;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        // Require permission
        $this->requirePermission('campaign-settings');

        $this->_settings = Campaign::$plugin->getSettings();
    }

    /**
     * @param SettingsModel $settings The settings being edited, if there were any validation errors.
     *
     * @return Response
     */
    public function actionEditGeneral(SettingsModel $settings = null): Response
    {
        if ($settings === null) {
            $settings = $this->_settings;
        }

        $config = Craft::$app->getConfig()->getConfigFromFile('campaign');

        return $this->renderTemplate('campaign/settings/general', [
            'settings' => $settings,
            'config' => $config,
        ]);
    }

    /**
     * @param SettingsModel|null             $settings The settings being edited, if there were any validation errors.
     * @param TransportAdapterInterface|null $adapter  The transport adapter, if there were any validation errors.
     *
     * @return Response
     * @throws MissingComponentException
     */
    public function actionEditEmail(SettingsModel $settings = null, TransportAdapterInterface $adapter = null): Response
    {
        if ($settings === null) {
            $settings = $this->_settings;
        }

        if ($adapter === null) {
            $settings->transportType = $settings->transportType ?: Sendmail::class;
            try {
                $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);
            }
            catch (MissingComponentException $e) {
                $adapter = new Sendmail();
                $adapter->addError('type', Craft::t('app', 'The transport type “{type}” could not be found.', [
                    'type' => $settings->transportType
                ]));
            }
        }

        // Get all the registered transport adapter types
        $allTransportAdapterTypes = MailerHelper::allMailerTransportTypes();

        // Make sure the selected adapter class is in there
        if (!\in_array(\get_class($adapter), $allTransportAdapterTypes, true)) {
            $allTransportAdapterTypes[] = \get_class($adapter);
        }

        $allTransportAdapters = [];
        $transportTypeOptions = [];

        foreach ($allTransportAdapterTypes as $transportAdapterType) {
            /** @var string|TransportAdapterInterface $transportAdapterType */
            if ($transportAdapterType === \get_class($adapter) || $transportAdapterType::isSelectable()) {
                $allTransportAdapters[] = MailerHelper::createTransportAdapter($transportAdapterType);
                $transportTypeOptions[] = [
                    'value' => $transportAdapterType,
                    'label' => $transportAdapterType::displayName()
                ];
            }
        }

        // Sort them by name
        ArrayHelper::multisort($transportTypeOptions, 'label');

        return $this->renderTemplate('campaign/settings/email', [
            'settings' => $settings,
            'adapter' => $adapter,
            'allTransportAdapters' => $allTransportAdapters,
            'transportTypeOptions' => $transportTypeOptions,
        ]);
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     */
    public function actionSaveGeneral()
    {
        $this->requirePostRequest();

        $settings = $this->_settings;

        // Set the simple stuff
        $settings->testMode = Craft::$app->getRequest()->getBodyParam('testMode', $settings->testMode);
        $settings->apiKey = Craft::$app->getRequest()->getBodyParam('apiKey', $settings->apiKey);

        // Save it
        if (!Campaign::$plugin->settings->saveSettings($settings)) {
            Craft::$app->getSession()->setError(Craft::t('app', 'Couldn’t save general settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'General settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response|null
     * @throws MissingComponentException
     * @throws BadRequestHttpException
     */
    public function actionSaveEmail()
    {
        $this->requirePostRequest();

        $settings = $this->_settings;

        // Set the simple stuff
        $settings->defaultFromName = Craft::$app->getRequest()->getBodyParam('defaultFromName', $settings->defaultFromName);
        $settings->defaultFromEmail = Craft::$app->getRequest()->getBodyParam('defaultFromEmail', $settings->defaultFromEmail);
        $settings->transportType = Craft::$app->getRequest()->getBodyParam('transportType', $settings->transportType);
        $settings->transportSettings = Craft::$app->getRequest()->getBodyParam('transportTypes.'.$settings->transportType);

        // Create the transport adapter so that we can validate it
        /* @var BaseTransportAdapter $adapter */
        $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);

        // Trigger before save event to give transport adapters a chance to encrypt sensitive data
        $adapter->beforeSave(true);

        // Validate settings and transport adapter
        $settings->validate();
        $adapter->validate();

        if ($settings->hasErrors() OR $adapter->hasErrors()) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save email settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
                'adapter' => $adapter
            ]);

            return null;
        }

        // Save it
        Campaign::$plugin->settings->saveSettings($settings);
        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Email settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws Exception
     */
    public function actionSaveContact()
    {
        $this->requirePostRequest();

        $settings = $this->_settings;

        // Set the simple stuff
        $settings->emailFieldLabel = Craft::$app->getRequest()->getBodyParam('emailFieldLabel', $settings->emailFieldLabel);

        // Set the field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = ContactElement::class;
        Craft::$app->getFields()->saveLayout($fieldLayout);
        $settings->contactFieldLayoutId = $fieldLayout->id;

        // Save it
        if (!Campaign::$plugin->settings->saveSettings($settings)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save contact settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Contact settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @throws MissingComponentException
     * @throws BadRequestHttpException
     */
    public function actionSendTestEmail()
    {
        $this->requirePostRequest();

        $settings = $this->_settings;

        // Set the simple stuff
        $settings->transportType = Craft::$app->getRequest()->getBodyParam('transportType', $settings->transportType);
        $settings->transportSettings = Craft::$app->getRequest()->getBodyParam('transportTypes.'.$settings->transportType);

        // Create the transport adapter so that we can validate it
        /* @var BaseTransportAdapter $adapter */
        $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);

        // Trigger before save event to give transport adapters a chance to encrypt sensitive data
        $adapter->beforeSave(true);

        // Validate settings and transport adapter
        $settings->validate();
        $adapter->validate();

        if ($settings->hasErrors() OR $adapter->hasErrors()) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t send test email.'));
        }
        else {
            // Create mailer with settings
            $mailer = Campaign::$plugin->createMailer($settings);

            $subject = Craft::t('campaign', 'This is a test email from Craft Campaign');
            $body = Craft::t('campaign', 'Congratulations! Craft Campaign was successfully able to send an email.');

            $message = $mailer->compose()
                ->setFrom([$settings->defaultFromEmail => $settings->defaultFromName])
                ->setTo(Craft::$app->getUser()->getIdentity())
                ->setSubject($subject)
                ->setHtmlBody($body)
                ->setTextBody($body);

            // Send message
            try {
                $response = $message->send();
            }
            catch (\Throwable $e) {
                Craft::error($e);
                Craft::$app->getErrorHandler()->logException($e);
                $response = false;
            }

            if ($response) {
                Craft::$app->getSession()->setNotice(Craft::t('app', 'Email sent successfully! Check your inbox.'));
            }
            else {
                Craft::$app->getSession()->setError(Craft::t('app', 'There was an error testing your email settings.'));
            }
        }

        // Send the settings back to the template
        Craft::$app->getUrlManager()->setRouteParams([
            'settings' => $settings,
            'adapter' => $adapter
        ]);
    }
}