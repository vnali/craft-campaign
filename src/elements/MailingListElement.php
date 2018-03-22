<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\elements;

use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\db\MailingListElementQuery;
use putyourlightson\campaign\helpers\StringHelper;
use putyourlightson\campaign\models\MailingListTypeModel;
use putyourlightson\campaign\records\ContactMailingListRecord;
use putyourlightson\campaign\records\MailingListRecord;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\actions\Edit;
use craft\elements\actions\Delete;
use craft\helpers\UrlHelper;
use yii\base\InvalidConfigException;

/**
 * MailingListElement
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0
 *
 * @property int                  $unsubscribedCount
 * @property int                  $complainedCount
 * @property ContactElement[]     $bouncedContacts
 * @property ContactElement[]     $unsubscribedContacts
 * @property int                  $bouncedCount
 * @property string               $reportUrl
 * @property int                  $pendingCount
 * @property ContactElement[]     $pendingContacts
 * @property int                  $subscribedCount
 * @property MailingListTypeModel $mailingListType
 * @property ContactElement[]     $complainedContacts
 * @property ContactElement[]     $subscribedContacts
 */
class MailingListElement extends Element
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('campaign', 'Mailing List');
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @return MailingListElementQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new MailingListElementQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('campaign', 'All mailing lists'),
                'criteria' => []
            ]
        ];

        $sources[] = ['heading' => Craft::t('campaign', 'Mailing List Types')];

        $mailingListTypes = Campaign::$plugin->mailingListTypes->getAllMailingListTypes();

        foreach ($mailingListTypes as $mailingListType) {
            $sources[] = [
                'key' => 'mailingListType:'.$mailingListType->id,
                'label' => $mailingListType->name,
                'data' => [
                    'handle' => $mailingListType->handle
                ],
                'criteria' => [
                    'mailingListTypeId' => $mailingListType->id
                ]
            ];
        }
        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source = null): array
    {
        $actions = [];

        // Edit
        $actions[] = Craft::$app->getElements()->createAction([
            'type' => Edit::class,
            'label' => Craft::t('campaign', 'Edit mailing list'),
        ]);

        // Delete
        $actions[] = Craft::$app->getElements()->createAction([
            'type' => Delete::class,
            'confirmationMessage' => Craft::t('campaign', 'Are you sure you want to delete the selected mailing lists?'),
            'successMessage' => Craft::t('campaign', 'Mailing lists deleted.'),
        ]);

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'elements.dateCreated' => Craft::t('app', 'Date Created'),
            'elements.dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        $attributes = [
            'title' => ['label' => Craft::t('app', 'Title')],
            'mailingListType' => ['label' => Craft::t('campaign', 'Mailing List Type')],
            'subscribed' => ['label' => Craft::t('campaign', 'Subscribed')],
            'unsubscribed' => ['label' => Craft::t('campaign', 'Unsubscribed')],
            'complained' => ['label' => Craft::t('campaign', 'Complained')],
            'bounced' => ['label' => Craft::t('campaign', 'Bounced')],
            'slug' => ['label' => Craft::t('app', 'Slug')],
            'id' => ['label' => Craft::t('app', 'ID')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        $attributes = [];

        if ($source === '*') {
            $attributes[] = 'mailingListType';
        }

        $attributes = array_merge($attributes, ['subscribed', 'unsubscribed', 'complained', 'bounced']);

        return $attributes;
    }

    // Properties
    // =========================================================================

    /**
     * @var string Mailing list ID
     */
    public $mlid;

    /**
     * @var int|null Mailing list type ID
     */
    public $mailingListTypeId;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['mailingListTypeId'], 'number', 'integerOnly' => true];
        $rules[] = [['mlid'], 'string', 'max' => 32];

        return $rules;
    }

    /**
     * Returns the number of subscribed contacts
     *
     * @return int
     */
    public function getSubscribedCount(): int
    {
        return $this->_getContactCount('subscribed');
    }

    /**
     * Returns the number of pending contacts
     *
     * @return int
     */
    public function getPendingCount(): int
    {
        return $this->_getContactCount('pending');
    }

    /**
     * Returns the number of unsubscribed contacts
     *
     * @return int
     */
    public function getUnsubscribedCount(): int
    {
        return $this->_getContactCount('unsubscribed');
    }

    /**
     * Returns the number of complained contacts
     *
     * @return int
     */
    public function getComplainedCount(): int
    {
        return $this->_getContactCount('complained');
    }

    /**
     * Returns the number of bounced contacts
     *
     * @return int
     */
    public function getBouncedCount(): int
    {
        return $this->_getContactCount('bounced');
    }

    /**
     * Returns the subscribed contacts
     *
     * @return ContactElement[]
     */
    public function getSubscribedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('subscribed');
    }

    /**
     * Returns the pending contacts
     *
     * @return ContactElement[]
     */
    public function getPendingContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('pending');
    }

    /**
     * Returns the subscribed contacts
     *
     * @return ContactElement[]
     */
    public function getUnsubscribedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('unsubscribed');
    }

    /**
     * Returns the complained contacts
     *
     * @return ContactElement[]
     */
    public function getComplainedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('complained');
    }

    /**
     * Returns the bounced contacts
     *
     * @return ContactElement[]
     */
    public function getBouncedContacts(): array
    {
        return $this->_getContactsBySubscriptionStatus('bounced');
    }

    /**
     * Returns the mailing list's mailing list type
     *
     * @return MailingListTypeModel
     * @throws InvalidConfigException if [[mailingListTypeId]] is missing or invalid
     */
    public function getMailingListType(): MailingListTypeModel
    {
        if ($this->mailingListTypeId === null) {
            throw new InvalidConfigException('Mailing list is missing its mailing list type ID');
        }

        $mailingListType = Campaign::$plugin->mailingListTypes->getMailingListTypeById($this->mailingListTypeId);

        if ($mailingListType === null) {
            throw new InvalidConfigException('Invalid mailing list type ID: '.$this->mailingListTypeId);
        }

        return $mailingListType;
    }

    /**
     * @inheritdoc
     */
    public function getIsEditable(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getCpEditUrl()
    {
        return UrlHelper::cpUrl('campaign/mailinglists/'.$this->getMailingListType()->handle.'/'.$this->id);
    }

    /**
     * Returns the mailing list's report URL
     *
     * @return string
     */
    public function getReportUrl(): string
    {
        return UrlHelper::cpUrl('campaign/reports/mailinglists/'.$this->id);
    }

    // Indexes, etc.
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'subscribed':
                return $this->getSubscribedCount();

            case 'unsubscribed':
                return $this->getUnsubscribedCount();

            case 'complained':
                return $this->getComplainedCount();

            case 'bounced':
                return $this->getBouncedCount();
        }

        return parent::tableAttributeHtml($attribute);
    }

    /**
     * @inheritdoc
     */
    public function getEditorHtml(): string
    {
        // Get the title field
        $html = Craft::$app->getView()->renderTemplate('campaign/mailinglists/_includes/titlefield', [
            'mailingList' => $this
        ]);

        // Set the field layout ID
        $this->fieldLayoutId = $this->getMailingListType()->fieldLayoutId;

        $html .= parent::getEditorHtml();

        return $html;
    }

    // Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool
    {
        if ($isNew) {
            // Create unique ID
            $this->mlid = StringHelper::uniqueId('ml');
        }

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        if ($isNew) {
            $mailingListRecord = new MailingListRecord();
            $mailingListRecord->id = $this->id;
            $mailingListRecord->mlid = $this->mlid;
        } else {
            $mailingListRecord = MailingListRecord::findOne($this->id);
        }

        if ($mailingListRecord) {
            // Set attributes
            $mailingListRecord->mailingListTypeId = $this->mailingListTypeId;

            $mailingListRecord->save(false);
        }

        parent::afterSave($isNew);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the number of contacts in this mailing list
     *
     * @param string|null
     *
     * @return int
     */
    private function _getContactCount(string $subscriptionStatus = ''): int
    {
        $condition = ['mailingListId' => $this->id];

        if ($subscriptionStatus) {
            $condition['subscriptionStatus'] = $subscriptionStatus;
        }

        $count = ContactMailingListRecord::find()
            ->where($condition)
            ->count();

        return $count;
    }

    /**
     * Returns the contacts in this mailing list by subscription status
     *
     * @param string
     *
     * @return ContactElement[]
     */
    private function _getContactsBySubscriptionStatus(string $subscriptionStatus): array
    {
        $contacts = [];

        $contactMailingListRecords = ContactMailingListRecord::findAll([
            'mailingListId' => $this->id,
            'subscriptionStatus' => $subscriptionStatus
        ]);

        foreach ($contactMailingListRecords as $contactMailingListRecord) {
            $contact = Campaign::$plugin->contacts->getContactById($contactMailingListRecord->contactId);

            if ($contact !== null) {
                $contacts[] = $contact;
            }
        }

        return $contacts;
    }
}