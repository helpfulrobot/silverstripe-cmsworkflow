<?php
/**
 * Report showing removal requests I need to publish
 * 
 * @package cmsworkflow
 * @subpackage ThreeStep
 */
class ApprovedDeletions3StepReport extends SS_Report
{
    public function title()
    {
        return _t('ApprovedDeletions3StepReport.TITLE', "Approved deletions I need to publish");
    }
    public function sourceRecords($params, $sort, $limit)
    {
        increase_time_limit_to(120);
        
        $res = WorkflowThreeStepRequest::get_by_publisher(
            'WorkflowDeletionRequest',
            Member::currentUser(),
            array('Approved')
        );
        $doSet = new DataObjectSet();
        foreach ($res as $result) {
            if ($wf = $result->openWorkflowRequest()) {
                if (!$result->canDeleteFromLive()) {
                    continue;
                }
                $result->WFAuthorID = $wf->AuthorID;
                $result->WFApproverTitle = $wf->Approver()->Title;
                $result->WFAuthorTitle = $wf->Author()->Title;
                $result->WFApprovedWhen = $wf->ApprovalDate();
                $result->WFRequestedWhen = $wf->Created;
                $result->WFApproverID = $wf->ApproverID;
                $result->WFPublisherID = $wf->PublisherID;
                if (isset($_REQUEST['OnlyMine']) && $result->WFApproverID != Member::currentUserID()) {
                    continue;
                }
                $result->BacklinkCount = $result->BackLinkTracking()->Count();
                $doSet->push($result);
            }
        }
        
        if ($sort) {
            $parts = explode(' ', $sort);
            $field = $parts[0];
            $direction = $parts[1];
            
            if ($field == 'AbsoluteLink') {
                $sort = 'URLSegment ' . $direction;
            }
            if ($field == 'Subsite.Title') {
                $sort = 'SubsiteID ' . $direction;
            }
            
            $doSet->sort($sort);
        }
        
        if ($limit && $limit['limit']) {
            return $doSet->getRange($limit['start'], $limit['limit']);
        } else {
            return $doSet;
        }
    }
    public function columns()
    {
        return array(
            "Title" => array(
                "title" => "Page name",
                'formatting' => '<a href=\"admin/show/$ID\" title=\"Edit page\">$value</a>'
            ),
            "WFApproverTitle" => array(
                "title" => "Approver",
            ),
            "WFApprovedWhen" => array(
                "title" => "Approved",
                'casting' => 'SS_Datetime->Full'
            ),
            "WFAuthorTitle" => array(
                "title" => "Author",
            ),
            "WFRequestedWhen" => array(
                "title" => "Requested",
                'casting' => 'SS_Datetime->Full'
            ),
            'AbsoluteLink' => array(
                'title' => 'URL',
                'formatting' => '$value " . ($AbsoluteLiveLink ? "<a target=\"_blank\" href=\"$AbsoluteLiveLink\">(live)</a>" : "") . " <a target=\"_blank\" href=\"$value?stage=Stage\">(draft)</a>'
            ),
            "BacklinkCount" => array(
                "title" => "Incoming links",
                'formatting' => '".($value ? "<a href=\"admin/show/$ID#Root_Expiry\" title=\"View backlinks\">yes, $value</a>" : "none") . "'
            ),
        );
    }

    /**
     * This alternative columns method is picked up by SideReportWrapper
     */
    public function sideReportColumns()
    {
        return array(
            "Title" => array(
                "title" => "Page name",
                "link" => true,
            ),
            "WFAuthorTitle" => array(
                "title" => "Approver",
                "formatting" => 'Approved by $value',
            ),
            "WFApprovedWhen" => array(
                "title" => "When",
                "formatting" => ' on $value',
                'casting' => 'SS_Datetime->Full'
            ),
        );
    }
    public function canView()
    {
        return Object::has_extension('SiteTree', 'SiteTreeCMSThreeStepWorkflow');
    }
    public function parameterFields()
    {
        $params = new FieldSet();
        
        $params->push(new CheckboxField(
            "OnlyMine",
            "Only requests I approved"
        ));
        
        return $params;
    }
    
    public function group()
    {
        return _t('WorkflowRequest.WORKFLOW', 'Workflow');
    }
}
