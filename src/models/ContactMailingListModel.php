<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\models;

use putyourlightson\campaign\base\BaseModel;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\helpers\GeoIpHelper;

/**
 * ContactMailingListModel
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0
 *
 * @property string             $countryCode
 * @property ContactElement     $contact
 * @property string             $interaction
 * @property array              $location
 * @property MailingListElement $mailingList
 */
class ContactMailingListModel extends BaseModel
{
    // Constants
    // =========================================================================

    const INTERACTIONS = ['subscribed', 'unsubscribed', 'complained', 'bounced'];

    // Properties
    // =========================================================================

    /**
     * @var int|null ID
     */
    public $id;

    /**
     * @var int Contact ID
     */
    public $contactId;

    /**
     * @var int Mailing list ID
     */
    public $mailingListId;

    /**
     * @var string Subscription status
     */
    public $subscriptionStatus;

    /**
     * @var \DateTime|null Subscribed
     */
    public $subscribed;

    /**
     * @var \DateTime|null Unsubscribed
     */
    public $unsubscribed;

    /**
     * @var \DateTime|null Complained
     */
    public $complained;

    /**
     * @var \DateTime|null Bounced
     */
    public $bounced;

    /**
     * @var \DateTime|null Confirmed
     */
    public $confirmed;

    /**
     * @var string Source
     */
    public $source = '';

    /**
     * @var string Source URL
     */
    public $sourceUrl = '';

    /**
     * @var string|null Country
     */
    public $country;

    /**
     * @var string|null GeoIP
     */
    public $geoIp;

    /**
     * @var \DateTime
     */
    public $dateUpdated;

    // Public Methods
    // =========================================================================

    /**
     * Returns the contact
     *
     * @return ContactElement
     */
    public function getContact(): ContactElement
    {
        return Campaign::$plugin->contacts->getContactById($this->contactId);
    }

    /**
     * Returns the mailing list
     *
     * @return MailingListElement
     */
    public function getMailingList(): MailingListElement
    {
        return Campaign::$plugin->mailingLists->getMailingListById($this->mailingListId);
    }

    /**
     * Returns the country code
     *
     * @return string
     */
    public function getCountryCode(): string
    {
        return GeoIpHelper::getCountryCode($this->geoIp);
    }

    /**
     * Returns the location
     *
     * @return array
     */
    public function getLocation(): array
    {
        return GeoIpHelper::getLocation($this->geoIp);
    }

    /**
     * Returns the most significant interaction
     *
     * @return string
     */
    public function getInteraction(): string
    {
        $interactions = ['bounced', 'complained', 'unsubscribed', 'subscribed'];

        foreach ($interactions as $interaction) {
            if ($this->$interaction !== null) {
                return $interaction;
            }
        }

        return '';
    }
}