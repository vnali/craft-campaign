<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\controllers;

use Craft;
use craft\base\Element;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\queue\Queue;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use craft\web\View;
use DateTime;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\CampaignElement;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\elements\SegmentElement;
use putyourlightson\campaign\elements\SendoutElement;
use putyourlightson\campaign\fieldlayoutelements\sendouts\SendoutField;
use putyourlightson\campaign\models\AutomatedScheduleModel;
use putyourlightson\campaign\models\RecurringScheduleModel;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SendoutsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected int|bool|array $allowAnonymous = ['queue-pending-sendouts'];

    /**
     * Queues pending sendouts.
     */
    public function actionQueuePendingSendouts(): Response
    {
        // Require permission if posted from utility
        if ($this->request->getIsPost() && $this->request->getParam('utility')) {
            $this->requirePermission('campaign:utility');
        }
        else {
            // Verify API key
            $key = $this->request->getParam('key');
            $apiKey = App::parseEnv(Campaign::$plugin->getSettings()->apiKey);

            if ($key === null || empty($apiKey) || $key != $apiKey) {
                throw new ForbiddenHttpException('Unauthorised access.');
            }
        }

        $count = Campaign::$plugin->sendouts->queuePendingSendouts();

        if ($this->request->getParam('run')) {
            /** @var Queue $queue */
            $queue = Craft::$app->getQueue();
            $queue->run();
        }

        // If front-end site request
        if (Craft::$app->getView()->templateMode == View::TEMPLATE_MODE_SITE) {
            // Prep the response
            $this->response->content = $count;

            return $this->response;
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', '{count} pending sendout(s) queued.', ['count' => $count]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Returns the pending recipient count.
     */
    public function actionGetPendingRecipientCount(): Response
    {
        $sendout = $this->_getSendoutFromParamId();

        // Prep the response
        $this->response->content = $sendout->getPendingRecipientCount();

        return $this->response;
    }

    /**
     * Returns the HTML body.
     */
    public function actionGetHtmlBody(): Response
    {
        $sendoutId = $this->request->getRequiredParam('sendoutId');
        $sendout = Campaign::$plugin->sendouts->getSendoutById($sendoutId);

        if ($sendout === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Sendout not found.'));
        }

        // Prep the response
        $this->response->content = $sendout->getHtmlBody();

        return $this->response;
    }

    /**
     * Creates a new unpublished draft and redirects to its edit page.
     *
     * @see CategoriesController::actionCreate()
     * @since 2.0.0
     */
    public function actionCreate(string $sendoutType): Response
    {
        if (!isset(SendoutElement::sendoutTypes()[$sendoutType])) {
            throw new BadRequestHttpException("Invalid sendout type: $sendoutType");
        }

        /**
         * The create action expects attributes to be passed in as body params.
         * @see ElementsController::actionCreate()
         */
        $this->request->setBodyParams([
            'elementType' => SendoutElement::class,
            'sendoutType' => $sendoutType,
        ]);

        // If a campaign ID was passed in as a parameter (from campaign "save and send" button)
        if ($campaignId = $this->request->getParam('campaignId')) {
            // Set title and subject to campaign title
            $campaign = Campaign::$plugin->campaigns->getCampaignById($campaignId);

            if ($campaign) {
                $this->request->setBodyParams(
                    array_merge($this->request->getBodyParams(), [
                        'campaignId' => $campaign->id,
                        'title' => $campaign->title,
                        'subject' => $campaign->title,
                    ])
                );
            }
        }

        return Craft::$app->runAction('elements/create');
    }

    /**
     * Main edit page.
     */
    public function actionEdit(int $sendoutId): Response
    {
        $sendout = Campaign::$plugin->sendouts->getSendoutById($sendoutId);

        if ($sendout === null) {
            throw new BadRequestHttpException("Invalid sendout ID: $sendoutId");
        }

        if (!$sendout->getIsModifiable()) {
            return $this->redirect($sendout->getCpPreviewUrl());
        }

        // Set the selected subnav item by adding it to the global variables
        Craft::$app->view->getTwig()->addGlobal('selectedSubnavItem', 'sendouts');

        /** @var Response|CpScreenResponseBehavior $response */
        $response =  Craft::$app->runAction('elements/edit', [
            'elementId' => $sendoutId,
        ]);

        $response->submitButtonLabel = Craft::t('campaign', 'Save and Preview');
        $response->redirectUrl = $sendout->getCpPreviewUrl();

        return $response;
    }

    /**
     * Preview page.
     */
    public function actionPreview(int $sendoutId): Response
    {
        $sendout = Campaign::$plugin->sendouts->getSendoutById($sendoutId);

        if ($sendout === null) {
            throw new BadRequestHttpException("Invalid sendout ID: $sendoutId");
        }

        $campaign = $sendout->getCampaign();
        $campaignType = $campaign->getCampaignType();

        $variables = [
            'sendout' => $sendout,
            'settings' => Campaign::$plugin->getSettings(),
            'fullPageForm' => true,
            'contactElementType' => ContactElement::class,
            'contactElementCriteria' => [
                'status' => ContactElement::STATUS_ACTIVE,
            ],
            'testContacts' => $campaignType->getTestContactsWithDefault(),
            'actions' => [],
            'system' => [
                'memoryLimit' => ini_get('memory_limit'),
                'timeLimit' => ini_get('max_execution_time'),
            ],
            'isDynamicWebAliasUsed' => Campaign::$plugin->settings->isDynamicWebAliasUsed($sendout->siteId),
        ];

        // Call for max power
        Campaign::$plugin->maxPowerLieutenant();

        $sendoutField = new SendoutField();
        $sendoutField->formHtml($sendout);
        return $this->renderTemplate('campaign/sendouts/_view', $variables);
    }

    /**
     * Edit the sendout.
     */
    public function xactionEdit(string $sendoutType, int $sendoutId = null, string $siteHandle = null, SendoutElement $sendout = null): Response
    {
        $this->requirePermission('campaign:sendouts');

        // Check that the sendout type exists
        $sendoutTypes = SendoutElement::sendoutTypes();

        if (empty($sendoutTypes[$sendoutType])) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Sendout type not found.'));
        }

        // Get the sendout
        if ($sendout === null) {
            if ($sendoutId !== null) {
                $sendout = Campaign::$plugin->sendouts->getSendoutById($sendoutId);

                if ($sendout === null) {
                    throw new NotFoundHttpException(Craft::t('campaign', 'Sendout not found.'));
                }
            }
            else {
                $sendout = new SendoutElement();
                $sendout->sendoutType = $sendoutType;

                // If a campaign ID was passed in as a parameter (from campaign "save and send" button)
                $campaignId = $this->request->getParam('campaignId');
                if ($campaignId) {
                    // Set campaign ID
                    $sendout->campaignId = $campaignId;

                    // Set title and subject to campaign title
                    $campaign = $sendout->getCampaign();
                    $sendout->title = $campaign !== null ? $campaign->title : '';
                    $sendout->subject = $sendout->title;
                }
            }
        }

        // Get the site if site handle is set
        if ($siteHandle !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);

            $sendout->siteId = $site->id;
        }

        // Get from name and email options
        $fromNameEmailOptions = Campaign::$plugin->settings->getFromNameEmailOptions($sendout->siteId);

        // Get the schedule
        if ($sendoutType == 'automated') {
            $sendout->schedule = new AutomatedScheduleModel($sendout->schedule);
        }
        elseif ($sendoutType == 'recurring') {
            $sendout->schedule = new RecurringScheduleModel($sendout->schedule);
        }

        // Set the variables
        $variables = [
            'sendoutType' => $sendoutType,
            'sendoutId' => $sendoutId,
            'sendout' => $sendout,
            'fromNameEmailOptions' => $fromNameEmailOptions,
        ];

        // Campaign element selector variables
        $variables['campaignElementType'] = CampaignElement::class;
        $variables['campaignElementCriteria'] = [
            'siteId' => $sendout->site->id,
            'status' => [CampaignElement::STATUS_SENT, CampaignElement::STATUS_PENDING],
        ];

        // Mailing list element selector variables
        $variables['mailingListElementType'] = MailingListElement::class;
        $variables['mailingListElementCriteria'] = [
            'siteId' => $sendout->site->id,
            'status' => Element::STATUS_ENABLED,
        ];

        if (Campaign::$plugin->getIsPro()) {
            // Segment element selector variables
            $variables['segmentElementType'] = SegmentElement::class;
            $variables['segmentElementCriteria'] = [
                'siteId' => $sendout->site->id,
                'status' => Element::STATUS_ENABLED,
            ];
        }

        // Contact element selector variables
        $variables['contactElementType'] = ContactElement::class;
        $variables['contactElementCriteria'] = [
            'status' => ContactElement::STATUS_ACTIVE,
        ];

        // Get test contacts
        $variables['testContacts'] = [];

        $campaign = $sendout->getCampaign();

        if ($campaign !== null) {
            $campaignType = $campaign->getCampaignType();
            $variables['testContacts'] = $campaignType->getTestContactsWithDefault();
        }

        // Determine which actions should be available
        $variables['actions'] = [];

        if ($sendout->getIsPausable()) {
            $variables['actions'][0][] = [
                'action' => 'campaign/sendouts/pause',
                'redirect' => $sendout->getCpEditUrl(),
                'label' => Craft::t('campaign', 'Pause and Edit'),
                'confirm' => Craft::t('campaign', 'Are you sure you want to pause and edit this sendout?'),
            ];
            $variables['actions'][0][] = [
                'action' => 'campaign/sendouts/pause',
                'redirect' => 'campaign/sendouts',
                'label' => Craft::t('campaign', 'Pause'),
                'confirm' => Craft::t('campaign', 'Are you sure you want to pause this sendout?'),
            ];
        }

        if ($sendout->getIsCancellable()) {
            $variables['actions'][1][] = [
                'action' => 'campaign/sendouts/cancel',
                'destructive' => 'true',
                'redirect' => 'campaign/sendouts',
                'label' => Craft::t('campaign', 'Cancel'),
                'confirm' => Craft::t('campaign', 'Are you sure you want to cancel this sendout? It cannot be sent again if cancelled.'),
            ];
        }

        $variables['actions'][1][] = [
            'action' => 'campaign/sendouts/delete',
            'destructive' => 'true',
            'redirect' => 'campaign/sendouts',
            'label' => Craft::t('campaign', 'Delete'),
            'confirm' => Craft::t('campaign', 'Are you sure you want to delete this sendout?'),
        ];

        if ($sendoutType == 'automated' || $sendoutType == 'recurring') {
            // Get the interval options
            $variables['intervalOptions'] = $sendout->schedule->getIntervalOptions();
        }

        $variables['settings'] = Campaign::$plugin->getSettings();

        $variables['fullPageForm'] = true;

        // Render the template
        if ($sendout->getIsModifiable() && !$this->request->getParam('preview')) {
            return $this->renderTemplate('campaign/sendouts/_edit', $variables);
        }

        // Call for max power
        Campaign::$plugin->maxPowerLieutenant();

        // Get the system limits
        $variables['system'] = [
            'memoryLimit' => ini_get('memory_limit'),
            'timeLimit' => ini_get('max_execution_time'),
        ];

        $variables['isDynamicWebAliasUsed'] = Campaign::$plugin->settings->isDynamicWebAliasUsed($sendout->siteId);

        return $this->renderTemplate('campaign/sendouts/_view', $variables);
    }

    /**
     * Saves the sendout.
     */
    public function actionSave(): ?Response
    {
        // Require permission
        $this->requirePermission('campaign:sendouts');

        $this->requirePostRequest();

        $sendoutId = $this->request->getBodyParam('sendoutId');

        if ($sendoutId) {
            $sendout = $this->_getSendoutFromParamId();
        }
        else {
            $sendout = new SendoutElement();
        }

        // Set the attributes, defaulting to the existing values for whatever is missing from the post data
        $sendout->siteId = $this->request->getBodyParam('siteId', $sendout->siteId);
        $sendout->sendoutType = $this->request->getBodyParam('sendoutType', $sendout->sendoutType);
        $sendout->title = $this->request->getBodyParam('title', $sendout->title);
        $sendout->subject = $this->request->getBodyParam('subject', $sendout->subject);
        $sendout->notificationEmailAddress = $this->request->getBodyParam('notificationEmailAddress', $sendout->notificationEmailAddress);

        // Get from name and email
        $fromNameEmail = explode(':', $this->request->getBodyParam('fromNameEmail', ''));
        $sendout->fromName = $fromNameEmail[0] ?? '';
        $sendout->fromEmail = $fromNameEmail[1] ?? '';
        $sendout->replyToEmail = $fromNameEmail[2] ?? '';

        // Get the selected campaign ID
        $campaignId = $this->request->getBodyParam('campaignId', $sendout->campaignId);
        $sendout->campaignId = $campaignId[0] ?? null;

        // Get the selected included and excluded mailing list IDs and segment IDs
        $mailingListIds = $this->request->getBodyParam('mailingListIds', $sendout->mailingListIds);
        $sendout->mailingListIds = is_array($mailingListIds) ? implode(',', $mailingListIds) : '';
        $excludedMailingListIds = $this->request->getBodyParam('excludedMailingListIds', $sendout->excludedMailingListIds);
        $sendout->excludedMailingListIds = is_array($excludedMailingListIds) ? implode(',', $excludedMailingListIds) : '';

        $segmentIds = $this->request->getBodyParam('segmentIds', $sendout->segmentIds);
        $sendout->segmentIds = is_array($segmentIds) ? implode(',', $segmentIds) : '';

        // Convert send date
        $sendDate = $this->request->getBodyParam('sendDate', $sendout->sendDate);
        $sendout->sendDate = DateTimeHelper::toDateTime($sendDate) ?: new DateTime();

        if ($sendout->sendoutType == 'automated' || $sendout->sendoutType == 'recurring') {
            $schedule = $this->request->getBodyParam('schedule');

            if ($sendout->sendoutType == 'automated') {
                $sendout->schedule = new AutomatedScheduleModel($schedule);
            }
            else {
                $sendout->schedule = new RecurringScheduleModel($schedule);
            }

            // Convert end date and time of day or set to null
            $sendout->schedule->endDate = DateTimeHelper::toDateTime($sendout->schedule->endDate) ?: null;
            $sendout->schedule->timeOfDay = DateTimeHelper::toDateTime($sendout->schedule->timeOfDay) ?: null;

            // Validate schedule and sendout
            $sendout->schedule->validate();
            $sendout->validate();

            // If errors then send the sendout back to the template
            if ($sendout->schedule->hasErrors() || $sendout->hasErrors()) {
                Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save sendout.'));

                Craft::$app->getUrlManager()->setRouteParams([
                    'sendout' => $sendout,
                ]);

                return null;
            }
        }

        // Save it without propagating across all sites
        if (!Craft::$app->getElements()->saveElement($sendout, true, false)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save sendout.'));

            // Send the sendout back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'sendout' => $sendout,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Sendout saved.'));

        return $this->redirectToPostedUrl($sendout);
    }

    /**
     * Sends the sendout.
     */
    public function actionSend(): ?Response
    {
        // Require permission to send
        $this->requirePermission('campaign:sendSendouts');

        $this->requirePostRequest();

        $sendout = $this->_getSendoutFromParamId();

        // Store current user ID
        $sendout->senderId = Craft::$app->getUser()->getId();

        // Set status to pending
        $sendout->sendStatus = SendoutElement::STATUS_PENDING;

        // Save it
        if (!Craft::$app->getElements()->saveElement($sendout)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson(['errors' => $sendout->getErrors()]);
            }

            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save sendout.'));

            // Send the sendout back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'sendout' => $sendout,
            ]);

            return null;
        }

        // Log it
        Campaign::$plugin->log('Sendout "{title}" initiated by "{username}".', ['title' => $sendout->title]);

        // Queue pending sendouts
        Campaign::$plugin->sendouts->queuePendingSendouts();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Sendout saved.'));

        return $this->redirectToPostedUrl($sendout);
    }

    /**
     * Sends a test.
     */
    public function actionSendTest(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $contactIds = $this->request->getBodyParam('contactIds');
        $sendout = $this->_getSendoutFromParamId();

        // Validate test contacts
        if (empty($contactIds)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('campaign', 'At least one contact must be submitted.')]);
        }

        $contacts = Campaign::$plugin->contacts->getContactsByIds($contactIds);

        foreach ($contacts as $contact) {
            if (!Campaign::$plugin->sendouts->sendTest($sendout, $contact)) {
                return $this->asJson(['success' => false, 'error' => Craft::t('campaign', 'Couldn’t send test email.')]);
            }
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Pauses a sendout.
     */
    public function actionPause(): ?Response
    {
        $this->requirePostRequest();

        $sendout = $this->_getSendoutFromParamId();

        if (!Campaign::$plugin->sendouts->pauseSendout($sendout)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Sendout could not be paused.'));

            return null;
        }

        // Log it
        Campaign::$plugin->log('Sendout "{title}" paused by "{username}".', ['title' => $sendout->title]);

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Sendout paused.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Cancels a sendout.
     */
    public function actionCancel(): ?Response
    {
        $this->requirePostRequest();

        $sendout = $this->_getSendoutFromParamId();

        if (!Campaign::$plugin->sendouts->cancelSendout($sendout)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Sendout could not be cancelled.'));

            return null;
        }

        // Log it
        Campaign::$plugin->log('Sendout "{title}" cancelled by "{username}".', ['title' => $sendout->title]);

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Sendout cancelled.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes a sendout.
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();

        $sendout = $this->_getSendoutFromParamId();

        if (!Campaign::$plugin->sendouts->deleteSendout($sendout)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Sendout could not be deleted.'));

            return null;
        }

        // Log it
        Campaign::$plugin->log('Sendout "{title}" deleted by "{username}".', ['title' => $sendout->title]);

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Sendout deleted.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Gets a sendout from a param ID.
     */
    private function _getSendoutFromParamId(): SendoutElement
    {
        $sendoutId = $this->request->getRequiredParam('sendoutId');
        $sendout = Campaign::$plugin->sendouts->getSendoutById($sendoutId);

        if ($sendout === null) {
            throw new NotFoundHttpException(Craft::t('campaign', 'Sendout not found.'));
        }

        return $sendout;
    }
}
