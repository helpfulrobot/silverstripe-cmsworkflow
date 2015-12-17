<?php
/**
 * These tests test the reports that are available on
 * two and three step. Though we are testing it using
 * two step.
 *
 * @package cmsworkflow
 * @subpackage tests
 */
class WorkflowReportsTest extends FunctionalTest
{
    
    public static $fixture_file = 'cmsworkflow/tests/WorkflowReportsTest.yml';

    protected $requiredExtensions = array(
        'SiteTree' => array('SiteTreeCMSTwoStepWorkflow'),
        'SiteConfig' => array('SiteConfigTwoStepWorkflow'),
        'WorkflowRequest' => array('WorkflowTwoStepRequest'),
    );

    protected $illegalExtensions = array(
        'SiteTree' => array('SiteTreeCMSThreeStepWorkflow', 'SiteTreeSubsites'),
        'WorkflowRequest' => array('WorkflowThreeStepRequest'),
        'LeftAndMain' => array('LeftAndMainCMSThreeStepWorkflow'),
        'SiteConfig' => array('SiteConfigThreeStepWorkflow'),
    );
    
    public function setUp()
    {
        // Static publishing will just confuse things
        StaticPublisher::$disable_realtime = true;
        
        parent::setUp();
        
        $this->origLocale = i18n::get_locale();
        i18n::set_locale('en_NZ');
    }
    
    public function tearDown()
    {
        parent::tearDown();
        
        // Static publishing will just confuse things
        StaticPublisher::$disable_realtime = false;
        i18n::set_locale($this->origLocale);
    }
    
    public function testPagesScheduledForPublishingReport()
    {
        $report = new PagesScheduledForPublishingReport();
        $this->assertTrue(is_string($report->title()));
        $this->assertTrue(is_array($report->columns()));
        $this->assertTrue($report->canView());
        $this->assertTrue($report->parameterFields() instanceof FieldSet);
        
        $this->logInAs($this->objFromFixture('Member', 'admin'));
        
        // Test with no dates set
        SS_Datetime::set_mock_now('2010-02-14 00:00:00');
        $results = $report->sourceRecords(array(), '"Title" DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page3',
            'Page2'
        ));
        
        // Test with start date only
        $results = $report->sourceRecords(array(
            'StartDate' => array(
                'date' => '14/02/2010',
                'time' => '12:00 am'
            )
        ), 'Title DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page3',
            'Page2'
        ));
        
        // Test with end date only
        $results = $report->sourceRecords(array(
            'EndDate' => array(
                'date' => '14/02/2010',
                'time' => '12:00 am'
            )
        ), 'Title ASC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page1'
        ));
        
        // Test with start and end dates
        $results = $report->sourceRecords(array(
            'StartDate' => array(
                'date' => '04/02/2010',
                'time' => '12:00 am'
            ),
            'EndDate' => array(
                'date' => '12/02/2010',
                'time' => '12:00 am'
            )
        ), 'AbsoluteLink DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page1'
        ));
        
        // Test that records you cannot edit do not appear
        SS_Datetime::set_mock_now('2010-02-01 00:00:00');
        $this->logInAs($this->objFromFixture('Member', 'admin'));
        $this->assertEquals($report->sourceRecords(array(), '"Title" DESC', false)->Count(), 3);
        $this->logInAs($this->objFromFixture('Member', 'publisher'));
        $this->assertEquals($report->sourceRecords(array(), '"Title" DESC', false)->Count(), 2);
        
        SS_Datetime::clear_mock_now();
    }
    
    public function testPagesScheduledForPublishingReportIncludesVirtualPages()
    {
        $report = new PagesScheduledForPublishingReport();
        $this->logInAs($this->objFromFixture('Member', 'admin'));
        
        $page2 = $this->objFromFixture('SiteTree', 'pagepub2');
        $page3 = $this->objFromFixture('SiteTree', 'pagepub3');
        $virtualPage = new VirtualPage();
        $virtualPage->URLSegment = 'virtual';
        $virtualPage->CopyContentFromID = $page3->ID;
        $virtualPage->write();
        
        SS_Datetime::set_mock_now('2010-02-14 00:00:00');
        $results = $report->sourceRecords(array(), '"ID" DESC', false);
        // Can't test with titles as they'll be the same for virtual pages
        $this->assertEquals($results->column('ID'), array(
            $page3->ID,
            $virtualPage->ID,
            $page2->ID
        ));
        $this->assertEquals($results->column('EmbargoDate'), array(
            $page3->openWorkflowRequest()->EmbargoDate,
            $page3->openWorkflowRequest()->EmbargoDate,
            $page2->openWorkflowRequest()->EmbargoDate
        ));
        
        SS_Datetime::clear_mock_now();
    }
    
    public function testPagesScheduledForDeletionReport()
    {
        $report = new PagesScheduledForDeletionReport();
        $this->assertTrue(is_string($report->title()));
        $this->assertTrue(is_array($report->columns()));
        $this->assertTrue($report->canView());
        $this->assertTrue($report->parameterFields() instanceof FieldSet);
        
        $this->logInAs($this->objFromFixture('Member', 'admin'));
        
        $this->objFromFixture('SiteTree', 'pagedel1')->doPublish();
        $this->objFromFixture('SiteTree', 'pagedel2')->doPublish();
        $this->objFromFixture('SiteTree', 'pagedel3')->doPublish();

        // Test with no dates set
        SS_Datetime::set_mock_now('2010-02-14 00:00:00');
        $results = $report->sourceRecords(array(), '"Title" DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page3',
            'Page2'
        ));
        
        // Test with start date only
        $results = $report->sourceRecords(array(
            'StartDate' => array(
                'date' => '14/02/2010',
                'time' => '12:00 am'
            )
        ), 'Title DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page3',
            'Page2'
        ));
        
        // Test with end date only
        $results = $report->sourceRecords(array(
            'EndDate' => array(
                'date' => '14/02/2010',
                'time' => '12:00 am'
            )
        ), 'Title ASC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page1'
        ));
        
        // Test with start and end dates
        $results = $report->sourceRecords(array(
            'StartDate' => array(
                'date' => '04/02/2010',
                'time' => '12:00 am'
            ),
            'EndDate' => array(
                'date' => '12/02/2010',
                'time' => '12:00 am'
            )
        ), 'Title DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page1'
        ));
        
        // Test that records you cannot edit do not appear
        SS_Datetime::set_mock_now('2010-02-01 00:00:00');
        $this->logInAs($this->objFromFixture('Member', 'admin'));
        $this->assertEquals($report->sourceRecords(array(), '', false)->Count(), 3);
        $this->logInAs($this->objFromFixture('Member', 'publisher'));
        $this->assertEquals($report->sourceRecords(array(), '"Title" DESC', false)->Count(), 2);
        
        SS_Datetime::clear_mock_now();
    }
    
    public function testPagesScheduledForDeletionReportIncludesVirtualPages()
    {
        $report = new PagesScheduledForDeletionReport();
        $this->logInAs($this->objFromFixture('Member', 'admin'));
        
        $page1 = $this->objFromFixture('SiteTree', 'pagedel1');
        $page1->doPublish();
        $page2 = $this->objFromFixture('SiteTree', 'pagedel2');
        $page2->doPublish();
        $page3 = $this->objFromFixture('SiteTree', 'pagedel3');
        $page3->doPublish();

        $virtualPage = new VirtualPage();
        $virtualPage->URLSegment = 'virtual';
        $virtualPage->CopyContentFromID = $page3->ID;
        $virtualPage->write();
        
        SS_Datetime::set_mock_now('2010-02-14 00:00:00');
        $results = $report->sourceRecords(array(), '"ID" DESC', false);
        // Can't test with titles as they'll be the same for virtual pages
        $this->assertEquals($results->column('ID'), array(
            $page3->ID,
            $virtualPage->ID,
            $page2->ID
        ));
        $this->assertEquals($results->column('ExpiryDate'), array(
            $page3->ExpiryDate,
            $page3->ExpiryDate,
            $page2->ExpiryDate
        ));
        
        SS_Datetime::clear_mock_now();
    }
    
    public function testRecentlyPublishedPagesReport()
    {
        $report = new RecentlyPublishedPagesReport();
        $this->assertTrue(is_string($report->title()));
        $this->assertTrue(is_array($report->columns()));
        $this->assertTrue($report->canView());
        $this->assertTrue($report->parameterFields() instanceof FieldSet);
        
        $this->logInAs($this->objFromFixture('Member', 'admin'));
        
        SS_Datetime::set_mock_now('2010-02-10 15:00:00');
        $page1 = new Page();
        $page1->Title = 'Page1';
        $page1->write();
        $wfr = $page1->openOrNewWorkflowRequest('WorkflowPublicationRequest');
        $wfr->request('Request');
        $wfr->approve('Approved');
        SS_Datetime::set_mock_now('2010-02-15 15:00:00');
        $page2 = new Page();
        $page2->Title = 'Page2';
        $page2->write();
        $wfr = $page2->openOrNewWorkflowRequest('WorkflowPublicationRequest');
        $wfr->request('Request');
        $wfr->approve('Approved');
        SS_Datetime::set_mock_now('2010-02-16 15:00:00');
        $page3 = new Page();
        $page3->Title = 'Page3';
        $page3->write();
        $wfr = $page3->openOrNewWorkflowRequest('WorkflowPublicationRequest');
        $wfr->request('Request');
        $wfr->approve('Approved');
        
        SS_Datetime::set_mock_now('2010-02-14 00:00:00');
        // Test with no dates set
        $results = $report->sourceRecords(array(), '"Title" DESC', false);//die();
        $this->assertEquals($results->column('Title'), array(
            'Page3',
            'Page2'
        ));
        
        // Test with start date only
        $results = $report->sourceRecords(array(
            'StartDate' => array(
                'date' => '14/02/2010',
                'time' => '12:00 am'
            )
        ), '"Title" DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page3',
            'Page2'
        ));
        
        // Test with end date only
        $results = $report->sourceRecords(array(
            'EndDate' => array(
                'date' => '14/02/2010',
                'time' => '12:00 am'
            )
        ), '"Title" ASC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page1'
        ));
        
        // Test with start and end dates
        $results = $report->sourceRecords(array(
            'StartDate' => array(
                'date' => '04/02/2010',
                'time' => '12:00 am'
            ),
            'EndDate' => array(
                'date' => '12/02/2010',
                'time' => '12:00 am'
            )
        ), '"Title" DESC', false);
        $this->assertEquals($results->column('Title'), array(
            'Page1'
        ));
        
        SS_Datetime::clear_mock_now();
    }
}
