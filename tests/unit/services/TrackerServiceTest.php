<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaigntests\unit\services;

use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\elements\SendoutElement;
use putyourlightson\campaign\records\ContactCampaignRecord;
use putyourlightson\campaign\records\LinkRecord;
use putyourlightson\campaigntests\unit\BaseUnitTest;
use putyourlightson\campaigntests\fixtures\CampaignsFixture;
use putyourlightson\campaigntests\fixtures\ContactsFixture;
use putyourlightson\campaigntests\fixtures\LinksFixture;
use putyourlightson\campaigntests\fixtures\SendoutsFixture;

/**
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.12.2
 */

class TrackerServiceTest extends BaseUnitTest
{
    // Fixtures
    // =========================================================================

    /**
     * @return array
     */
    public function _fixtures(): array
    {
        return [
            'contacts' => [
                'class' => ContactsFixture::class
            ],
            'campaigns' => [
                'class' => CampaignsFixture::class
            ],
            'sendouts' => [
                'class' => SendoutsFixture::class
            ],
            'links' => [
                'class' => LinksFixture::class
            ],
        ];
    }

    // Properties
    // =========================================================================

    /**
     * @var ContactElement
     */
    protected $contact;

    /**
     * @var SendoutElement
     */
    protected $sendout;

    /**
     * @var MailingListElement
     */
    protected $mailingList;

    // Protected methods
    // =========================================================================

    protected function _before()
    {
        $this->contact = ContactElement::find()->one();
        $this->sendout = SendoutElement::find()->one();
        $this->mailingList = $this->sendout->getMailingLists()[0];

        // Create contact campaign record
        $contactCampaignRecord = new ContactCampaignRecord([
            'contactId' => $this->contact->id,
            'campaignId' => $this->sendout->campaignId,
            'sendoutId' => $this->sendout->id,
            'mailingListId' => $this->mailingList->id,
        ]);
        $contactCampaignRecord->save();

        Campaign::$plugin->mailingLists->addContactInteraction($this->contact, $this->mailingList, 'subscribed');
    }

    // Public methods
    // =========================================================================

    public function testOpen()
    {
        Campaign::$plugin->tracker->open($this->contact, $this->sendout);

        $campaign = Campaign::$plugin->campaigns->getCampaignById($this->sendout->campaignId);

        // Assert that the campaign has an open
        $this->assertEquals(1, $campaign->opens);
    }

    public function testClick()
    {
        $link = LinkRecord::find()->one();

        Campaign::$plugin->tracker->click($this->contact, $this->sendout, $link);

        $campaign = Campaign::$plugin->campaigns->getCampaignById($this->sendout->campaignId);
        $link = LinkRecord::findOne(['id' => $link->id]);

        // Assert that the campaign has a click
        $this->assertEquals(1, $campaign->clicks);

        // Assert that the link has a click
        $this->assertEquals(1, $link->clicks);
    }

    public function testUnsubscribe()
    {
        $status = $this->contact->getMailingListSubscriptionStatus($this->mailingList->id);

        // Assert that the contact is subscribed
        $this->assertEquals('subscribed', $status);

        Campaign::$plugin->tracker->unsubscribe($this->contact, $this->sendout);

        $status = $this->contact->getMailingListSubscriptionStatus($this->mailingList->id);

        // Assert that the contact is unsubscribed
        $this->assertEquals('unsubscribed', $status);
    }
}
