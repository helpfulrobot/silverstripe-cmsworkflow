<?php
/**
 * A "publication request" is created when an author without
 * publish rights changes a page in draft mode, and explicitly
 * requests it to be reviewed for publication.
 * Each request can have one or more "Publishers" which
 * should have permissions to publish the specific page.
 *
 * @package cmsworkflow
 */
class WorkflowPublicationRequest extends WorkflowRequest
{
    public static function create_for_page($page, $author = null, $approvers = null, $notify = true)
    {
        if (!$author && $author !== false) {
            $author = Member::currentUser();
        }
    
        // take all members from the PublisherGroups relation on this record as a default
        if (!$approvers) {
            $approvers = $page->whoCanApprove();
        }

        // if no publishers are set, the request will end up nowhere
        if (!$approvers->Count()) {
            user_error("No publishers selected", E_USER_ERROR);
            return null;
        }

        if (!self::can_create($author, $page)) {
            user_error("No create permnission for $author->ID on $page->ID", E_USER_ERROR);
            return null;
        }
    
        // get or create a publication request
        $request = $page->OpenWorkflowRequest();
        if (!$request || !$request->ID) {
            $request = new WorkflowPublicationRequest();
            $request->PageID = $page->ID;
        }

        // @todo Check for correct workflow class (a "publication" request might be overwritten with a "deletion" request)

        // @todo reassign original author as a reviewer if present
        $request->AuthorID = $author->ID;
        $request->write();

        // assign publishers to this specific request
        foreach ($approvers as $approver) {
            $request->Approvers()->add($approver);
        }

        // open the request and notify interested parties
        $request->Status = 'AwaitingApproval';
        $request->write();
    
        return $request;
    }
    
    /**
     * @param FieldSet $actions
     * @parma SiteTree $page
     */
    public static function update_cms_actions(&$actions, $page)
    {
        $openRequest = $page->OpenWorkflowRequest();

        // if user doesn't have publish rights
        if (!$page->canPublish() || $openRequest) {
            // authors shouldn't be able to revert, as this republishes the page.
            // they should rather change the page and re-request publication
            $actions->removeByName('action_revert');
        }
        
        // Remove the one click publish if they are not an admin/workflow admin.
        if (self::$force_publishers_to_use_workflow && !Permission::checkMember(Member::currentUser(), 'IS_WORKFLOW_ADMIN')) {
            $actions->removeByName('action_publish');
        }
        // Remove the save & publish button if you don't have edit rights
        if (!$page->canEdit()) {
            $actions->removeByName('action_publish');
        }
        
        $liveVersion = Versioned::get_one_by_stage('SiteTree', 'Live', "\"SiteTree_Live\".\"ID\" = {$page->ID}");
        if ($liveVersion && $liveVersion->ExpiryDate != null && $liveVersion->ExpiryDate != '0000-00-00 00:00:00') {
            if ($page->canApprove()) {
                $actions->push(new FormAction(
                    'cms_cancelexpiry',
                    _t('WorkflowPublicationRequest.BUTTONCANCELEXPIRY', 'Cancel expiry')
                ));
            }
        }
        
        // Optional method
        $isPublishable = $page->hasMethod('isPublishable') ? $page->isPublishable() : true;
        
        if (
            !$openRequest
            && $page->canEdit()
            && $isPublishable
            && $page->stagesDiffer('Stage', 'Live')
            && ($page->Version > 1 || $page->Title != "New Page") // page has been saved at least once
            && !$page->IsDeletedFromStage
            && (!$page->canPublish() || self::$publisher_can_create_wf_requests)
        ) {
            $actions->push(
                $requestPublicationAction = new FormAction(
                    'cms_requestpublication',
                    _t('SiteTreeCMSWorkflow.BUTTONREQUESTPUBLICATION', 'Request Publication')
                )
            );
            // don't allow creation of a second request by another author
            if (!self::can_create(null, $page)) {
                $actions->makeFieldReadonly($requestPublicationAction->Name());
            }
        }
    }
    
    public function ViewEmbargoedLink()
    {
        return $this->Page()->AbsoluteLink() . '?futureDate=' . $this->dbObject('EmbargoDate')->URLDatetime();
    }
    
    public function ViewExpiredLink()
    {
        return $this->Page()->ViewExpiredLink();
    }
    
    public function publish($comment, $member, $notify)
    {
        if (!$member) {
            $member = Member::currentUser();
        }
        
        // We have to mark as completed now, or we'll get
        // recursion from SiteTreeCMSWorkflow::onAfterPublish.
        $page = $this->Page();
        $page->LatestCompletedWorkflowRequestID = $this->ID;
        $page->writeWithoutVersion();

        // Embargo means we go Approved -> Scheduled
        if ($this->EmbargoDate) {
            $this->setSchedule();
            $this->PublisherID = $member->ID;
            $this->write();

        // Otherwise we go Approved -> Published
        } else {
            $this->Status = 'Completed';
            $this->PublisherID = $member->ID;
            $this->write();
        
            // Only publish the page if it hasn't already been published elsewhere.  This occurs when
            // SiteTree::doPublish() 'auto-closes' an open workflow
            //if($page->getIsModifiedOnStage()) {
                $page->doPublish();
            //}

            if ($notify) {
                $this->notifyPublished($comment);
            }
        }
        
        $this->addNewChange($comment, $this->Status, DataObject::get_by_id('Member', $this->PublisherID));

        // @todo Coupling to UI :-(
        $title = Convert::raw2js($page->TreeTitle());
        FormResponse::add("$('sitetree').setNodeTitle($page->ID, \"$title\");");
        
        return _t('SiteTreeCMSWorkflow.PUBLISHMESSAGE', 'Published changes to live version. Emailed %s.');
    }
    
    /**
     * @param Member $member
     * @param SiteTree $page
     * @return boolean
     */
    public static function can_create($member = null, $page)
    {
        if (!$member && $member !== false) {
            $member = Member::currentUser();
        }
        
        // if user can't edit page, he shouldn't be able to request publication
        if (!$page->canEdit($member)) {
            return false;
        }

        $request = $page->OpenWorkflowRequest();
        
        // if no request exists, allow creation of a new one (we can just have one open request at each point in time)
        if (!$request || !$request->ID) {
            return true;
        }
        
        // members can re-submit their own publication requests
        if ($member && $member->ID == $request->AuthorID) {
            return true;
        }

        return true;
    }
    
    public function onAfterPublish($page, $member)
    {
        $this->ApproverID = $member->ID;
        $this->write();
        // open the request and notify interested parties
        $this->Status = 'Approved';
        $this->write();
        $this->notifyApproved($comment);
    }
}
