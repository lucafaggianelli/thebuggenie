<?php

    namespace thebuggenie\core\modules\project;

    use thebuggenie\core\framework,
        thebuggenie\core\entities,
        thebuggenie\core\entities\tables;

    /**
     * actions for the project module
     */
    class Actions extends framework\Action
    {
        /**
         * The currently selected project
         *
         * @var entities\Project
         * @access protected
         * @property $selected_project
         */
        /**
         * The currently selected client
         *
         * @var entities\Client
         * @access protected
         * @property $selected_client
         */

        /**
         * Pre-execute function
         *
         * @param framework\Request     $request
         * @param string        $action
         */
        public function preExecute(framework\Request $request, $action)
        {
            if ($project_id = $request['project_id'])
            {
                try
                {
                    $this->selected_project = entities\Project::getB2DBTable()->selectById($project_id);
                }
                catch (\Exception $e)
                {

                }
            }
            elseif ($project_key = $request['project_key'])
            {
                try
                {
                    $this->selected_project = entities\Project::getByKey($project_key);
                }
                catch (\Exception $e)
                {

                }
            }
            if ($this->selected_project instanceof entities\Project)
            {
                framework\Context::setCurrentProject($this->selected_project);
                $this->project_key = $this->selected_project->getKey();
            }
            else
            {
                $this->return404(framework\Context::getI18n()->__('This project does not exist'));
            }
        }

        protected function _checkProjectPageAccess($page)
        {
            return framework\Context::getUser()->hasProjectPageAccess($page, $this->selected_project);
        }

        /**
         * The project dashboard
         *
         * @param framework\Request $request
         */
        public function runDashboard(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_dashboard'));

            if ($request->isPost() && $request['setup_default_dashboard'] && $this->getUser()->canEditProjectDetails($this->selected_project))
            {
                entities\DashboardView::getB2DBTable()->setDefaultViews($this->selected_project->getID(), entities\DashboardView::TYPE_PROJECT);
                $this->forward($this->getRouting()->generate('project_dashboard', array('project_key' => $this->selected_project->getKey())));
            }
            if ($request['dashboard_id'])
            {
                foreach ($this->selected_project->getDashboards() as $db)
                {
                    if ($db->getID() == (int) $request['dashboard_id'])
                    {
                        $dashboard = $db;
                        break;
                    }
                }
            }

            if (!isset($dashboard) || !$dashboard instanceof entities\Dashboard)
            {
                $dashboard = $this->selected_project->getDefaultDashboard();
            }

            $this->dashboard = $dashboard;
        }

        /**
         * The project files page
         *
         * @param framework\Request $request
         */
        public function runFiles(framework\Request $request)
        {

        }

        /**
         * The project roadmap page
         *
         * @param framework\Request $request
         */
        public function runRoadmap(framework\Request $request)
        {
            $this->mode = $request->getParameter('mode', 'upcoming');
            if ($this->mode == 'milestone' && $request['milestone_id'])
            {
                $this->selected_milestone = tables\Milestones::getTable()->selectById((int) $request['milestone_id']);
            }
            $this->forward403unless($this->_checkProjectPageAccess('project_roadmap'));
            $this->milestones = $this->selected_project->getMilestonesForRoadmap();
        }

        /**
         * The project planning page
         *
         * @param framework\Request $request
         */
        public function runTimeline(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_timeline'));
            $offset = $request->getParameter('offset', 0);
            if ($request['show'] == 'important')
            {
                $this->recent_activities = $this->selected_project->getRecentActivities(40, true, $offset);
                $this->important = true;
            }
            else
            {
                $this->important = false;
                $this->recent_activities = $this->selected_project->getRecentActivities(40, false, $offset);
            }

            if ($offset)
            {
                return $this->renderJSON(array('content' => $this->getComponentHTML('project/timeline', array('activities' => $this->recent_activities)), 'offset' => $offset + 40));
            }
        }

        /**
         * Retrieves a list of all releases on a board
         *
         * @param framework\Request $request
         */
        public function runGetReleases(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning'));
            $board = tables\AgileBoards::getTable()->selectById($request['board_id']);

            return $this->renderComponent('project/releasestrip', compact('board'));
        }

        /**
         * Retrieves a list of all epics on a board
         *
         * @param framework\Request $request
         */
        public function runGetEpics(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning'));
            $board = tables\AgileBoards::getTable()->selectById($request['board_id']);

            return $this->renderComponent('project/epicstrip', compact('board'));
        }

        /**
         * Adds an epic
         *
         * @param framework\Request $request
         */
        public function runAddEpic(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning'));
            $board = tables\AgileBoards::getTable()->selectById($request['board_id']);

            try
            {
                $title = trim($request['title']);
                $shortname = trim($request['shortname']);
                if (!$title)
                    throw new \Exception($this->getI18n()->__('You have to provide a title'));
                if (!$shortname)
                    throw new \Exception($this->getI18n()->__('You have to provide a label'));

                $issue = new entities\Issue();
                $issue->setTitle($title);
                $issue->setShortname($shortname);
                $issue->setIssuetype($board->getEpicIssuetypeID());
                $issue->setProject($board->getProject());
                $issue->setPostedBy($this->getUser());
                $issue->save();

                return $this->renderJSON(array('issue_details' => $issue->toJSON()));
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $e->getMessage()));
            }
        }

        /**
         * Issue retriever for the project planning page
         *
         * @param framework\Request $request
         */
        public function runRetrievePlanningIssue(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning'));
            $board = tables\AgileBoards::getTable()->selectById($request['board_id']);
            $issue = entities\Issue::getB2DBTable()->selectById($request['issue_id']);

            $this->forward403unless($issue instanceof entities\Issue && $issue->hasAccess());

            if ($issue->isChildIssue() && !$issue->hasParentIssuetype($board->getEpicIssuetypeID()))
            {
                return $this->renderJSON(array('child_issue' => 1));
            }
            elseif ($issue->getIssueType()->getID() == $board->getEpicIssuetypeID())
            {
                return $this->renderJSON(array('child_issue' => 0, 'epic' => 1, 'component' => $this->getComponentHTML('project/milestoneepic', array('epic' => $issue, 'board' => $board)), 'issue_details' => $issue->toJSON()));
            }

            return $this->renderJSON(array('child_issue' => 0, 'component' => $this->getComponentHTML('project/milestoneissue', array('issue' => $issue, 'board' => $board)), 'issue_details' => $issue->toJSON()));
        }

        /**
         * Poller for the project planning page
         *
         * @param framework\Request $request
         */
        public function runPollPlanning(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning'));
            $last_refreshed = $request['last_refreshed'];
            $board = tables\AgileBoards::getTable()->selectById($request['board_id']);
            $search_object = $board->getBacklogSearchObject();
            $search_object->setFilter('last_updated', entities\SearchFilter::createFilter('last_updated', array('o' => \b2db\Criteria::DB_GREATER_THAN_EQUAL, 'v' => $last_refreshed - 2)));

            if ($request['mode'] == 'whiteboard')
            {
                $milestone_id = $request['milestone_id'];
                $ids = tables\Issues::getTable()->getUpdatedIssueIDsByTimestampAndProjectIDAndMilestoneID($last_refreshed - 2, $this->selected_project->getID(), $milestone_id);
            }
            else
            {
                $ids = tables\Issues::getTable()->getUpdatedIssueIDsByTimestampAndProjectIDAndIssuetypeID($last_refreshed - 2, $this->selected_project->getID());
                $epic_ids = ($board->getEpicIssuetypeID()) ? tables\Issues::getTable()->getUpdatedIssueIDsByTimestampAndProjectIDAndIssuetypeID($last_refreshed - 2, $this->selected_project->getID(), $board->getEpicIssuetypeID()) : array();
            }
            $backlog_ids = array();
            foreach ($search_object->getIssues() as $backlog_issue)
            {
                $backlog_ids[] = array('issue_id' => $backlog_issue->getID(), 'last_updated' => $backlog_issue->getLastUpdatedTime());
            }

            return $this->renderJSON(compact('ids', 'backlog_ids', 'epic_ids', 'milestone_id'));
        }

        /**
         * The project planning page
         *
         * @param framework\Request $request
         */
        public function runPlanning(framework\Request $request)
        {
            $boards = tables\AgileBoards::getTable()->getAvailableProjectBoards($this->getUser()->getID(), $this->selected_project->getID());
            $project_boards = array();
            $user_boards = array();
            foreach ($boards as $board)
            {
                if ($board->isPrivate())
                    $user_boards[$board->getID()] = $board;
                else
                    $project_boards[$board->getID()] = $board;
            }
            $this->project_boards = $project_boards;
            $this->user_boards = $user_boards;
        }

        /**
         * The project board whiteboard page
         *
         * @param framework\Request $request
         */
        public function runAgileboardWhiteboardColumn(framework\Request $request)
        {
            $board = tables\AgileBoards::getTable()->selectById($request['board_id']);
            $column = entities\BoardColumn::getB2DBTable()->selectById($request['column_id']);
            if (!$column instanceof entities\BoardColumn)
            {
                $column = new entities\BoardColumn();
                $column->setBoard($board);
            }

            return $this->renderJSON(array('content' => $this->getComponentHTML('project/editboardcolumn', array('column' => $column))));
        }

        /**
         * The project board whiteboard page
         *
         * @param framework\Request $request
         */
        public function runAgileboardWhiteboard(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning_board'));
            $this->board = tables\AgileBoards::getTable()->selectById($request['board_id']);

            $this->forward403unless($this->board instanceof entities\AgileBoard);

            if ($request->isAjaxCall())
            {
                try
                {
                    switch ($request['mode'])
                    {
                        case 'getmilestonestatus':
                            $milestone = tables\Milestones::getTable()->selectById((int) $request['milestone_id']);
                            return $this->renderJSON(array('content' => $this->getComponentHTML('project/milestonewhiteboardstatusdetails', array('milestone' => $milestone))));
                        case 'whiteboardissues':
                            if ($request->isPost())
                            {
                                $issue = tables\Issues::getTable()->selectById((int) $request['issue_id']);
                                $column = entities\BoardColumn::getB2DBTable()->selectById((int) $request['column_id']);
                                $milestone = entities\Milestone::getB2DBTable()->selectById((int) $request['milestone_id']);

                                $swimlane = null;
                                if ($request['swimlane_identifier'])
                                {
                                    foreach ($column->getBoard()->getMilestoneSwimlanes($milestone) as $swimlane)
                                    {
                                        if ($swimlane->getIdentifier() == $request['swimlane_identifier']) break;
                                    }
                                }

                                if ($request->hasParameter('transition_id'))
                                {
                                    $transitions = array(tables\WorkflowTransitions::getTable()->selectById((int) $request['transition_id']));
                                }
                                else
                                {
                                    $status_ids = array();
                                    $transitions = array();

                                    foreach ($issue->getAvailableWorkflowTransitions() as $transition)
                                    {
                                        $status_ids[] = $transition->getOutgoingStep()->getLinkedStatusID();
                                        $transitions[$transition->getOutgoingStep()->getLinkedStatusID()] = $transition;
                                    }

                                    $available_statuses = array_intersect($status_ids, $column->getStatusIds());

                                    if (empty($available_statuses))
                                    {
                                        $this->getResponse()->setHttpStatus(400);
                                        return $this->renderJSON(array('error' => $this->getI18n()->__('There are no valid transitions to any states in this column')));
                                    }

                                    if (count($available_statuses) > 1)
                                    {
                                        return $this->renderJSON(array('component' => $this->getComponentHTML('project/agilewhiteboardtransitionselector', array('issue' => $issue, 'transitions' => $transitions, 'statuses' => $available_statuses, 'new_column' => $column, 'board' => $column->getBoard(), 'swimlane_identifier' => $request['swimlane_identifier']))));
                                    }
                                }

                                current($transitions)->transitionIssueToOutgoingStepWithoutRequest($issue);
                                return $this->renderJSON(array('transition' => 'ok', 'issue' => $this->getComponentHTML('project/whiteboardissue', array('issue' => $issue, 'column' => $column, 'swimlane' => $swimlane))));
                            }
                            else
                            {
                                $milestone = tables\Milestones::getTable()->selectById((int) $request['milestone_id']);
                                return $this->renderJSON(array('component' => $this->getComponentHTML('project/agilewhiteboardcontent', array('board' => $this->board, 'milestone' => $milestone))));
                            }
                        default:
                            if ($request->isPost())
                            {
                                $columns = $request['columns'];
                                $saved_columns = array();
                                $cc = 1;
                                if (is_array($columns))
                                {
                                    foreach ($columns as $details)
                                    {
                                        if ($details['column_id'])
                                        {
                                            $column = entities\BoardColumn::getB2DBTable()->selectById($details['column_id']);
                                        }
                                        else
                                        {
                                            $column = new entities\BoardColumn();
                                            $column->setBoard($this->board);
                                        }
                                        if (!$column instanceof entities\BoardColumn)
                                        {
                                            throw new \Exception($this->getI18n()->__('There was an error trying to save column %column', array('%column' => $details['column_id'])));
                                        }
                                        $column->setName($details['name']);
                                        $column->setSortOrder($details['sort_order']);
                                        if (array_key_exists('min_workitems', $details)) $column->setMinWorkitems($details['min_workitems']);
                                        if (array_key_exists('max_workitems', $details)) $column->setMaxWorkitems($details['max_workitems']);
                                        $column->setStatusIds(explode(',', $details['status_ids']));
                                        $column->save();
                                        $saved_columns[$column->getID()] = $column->getID();
                                        $cc++;
                                    }
                                }
                                foreach ($this->board->getColumns() as $column)
                                {
                                    if (!array_key_exists($column->getID(), $saved_columns))
                                    {
                                        $column->delete();
                                    }
                                }
                                return $this->renderJSON(array('forward' => $this->getRouting()->generate('project_planning_board_whiteboard', array('project_key' => $this->board->getProject()->getKey(), 'board_id' => $this->board->getID()))));
                            }
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => $e->getMessage()));
                }
            }

            $this->selected_milestone = null;
            foreach ($this->board->getMilestones() as $milestone)
            {
                if (!$milestone->isReached())
                {
                    $this->selected_milestone = $milestone;
                    break;
                }
            }
        }

        /**
         * Sorting milestones
         *
         * @param framework\Request $request
         */
        public function runSortMilestones(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning_board'));
            $milestones = $request->getParameter('milestone_ids', array());

            try
            {
                if (is_array($milestones))
                {
                    foreach ($milestones as $order => $milestone_id)
                    {
                        $milestone = tables\Milestones::getTable()->selectByID($milestone_id);

                        if ($milestone->getProject()->getID() != $this->selected_project->getID())
                            continue;

                        $milestone->setOrder($order);
                        $milestone->save();
                    }
                }
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $this->getI18n()->__('An error occured when trying to save the milestone order')));
            }

            return $this->renderJSON(array('sorted' => 'ok'));
        }

        /**
         * The project planning page
         *
         * @param framework\Request $request
         */
        public function runAgileboardPlanning(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_planning_board'));
            $this->board = ($request['board_id']) ? tables\AgileBoards::getTable()->selectById($request['board_id']) : new entities\AgileBoard();
            if ($request->isDelete())
            {
                $board_id = $this->board->getID();
                $this->board->delete();
                return $this->renderJSON(array('message' => $this->getI18n()->__('The board has been deleted'), 'board_id' => $board_id));
            }
            elseif ($request->isPost())
            {
                $this->board->setName($request['name']);
                $this->board->setDescription($request['description']);
                $this->board->setType($request['type']);
                $this->board->setProject($this->selected_project);
                $this->board->setIsPrivate($request['is_private']);
                $this->board->setEpicIssuetype($request['epic_issuetype_id']);
                $this->board->setTaskIssuetype($request['task_issuetype_id']);
                list($type, $id) = explode('_', $request['backlog_search']);
                if ($type == 'predefined')
                {
                    $this->board->setAutogeneratedSearch($id);
                }
                else
                {
                    $this->board->setBacklogSearch($id);
                }
                $this->board->setUseSwimlanes((bool) $request['use_swimlane']);
                if ($this->board->usesSwimlanes())
                {
                    $details = $request['swimlane_'.$request['swimlane'].'_details'];
                    $this->board->setSwimlaneType($request['swimlane']);
                    $this->board->setSwimlaneIdentifier($request['swimlane_'.$request['swimlane'].'_identifier']);
                    if (isset($details[$this->board->getSwimlaneIdentifier()]))
                    {
                        $this->board->setSwimlaneFieldValues(explode(',', $details[$this->board->getSwimlaneIdentifier()]));
                    }
                }
                else
                {
                    $this->board->clearSwimlaneType();
                    $this->board->clearSwimlaneIdentifier();
                    $this->board->clearSwimlaneFieldValues();
                }
                $this->board->save();

                return $this->renderJSON(array('component' => $this->getComponentHTML('project/agileboardbox', array('board' => $this->board)), 'id' => $this->board->getID(), 'private' => $this->board->isPrivate(), 'backlog_search' => $this->board->getBacklogSearchIdentifier(), 'saved' => 'ok'));
            }
        }

        /**
         * The project scrum page
         *
         * @param framework\Request $request
         */
        public function runMilestoneDetails(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_scrum'));
            $milestone = null;
            if ($m_id = $request['milestone_id'])
            {
                $milestone = tables\Milestones::getTable()->selectById((int) $m_id);
            }
            return $this->renderComponent('project/milestonedetails', compact('milestone'));
        }

        /**
         * Show the scrum burndown chart for a specified sprint
         *
         * @param framework\Request $request
         */
        public function runScrumShowBurndownImage(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_scrum'));

            $milestone = null;
            $maxEstimation = 0;

            if ($m_id = $request['sprint_id'])
            {
                $milestone = entities\Milestone::getB2DBTable()->selectById($m_id);
            }
            else
            {
                $milestones = $this->selected_project->getUpcomingMilestones();
                if (count($milestones))
                {
                    $milestone = array_shift($milestones);
                }
            }

            $this->getResponse()->setContentType('image/png');
            $this->getResponse()->setDecoration(framework\Response::DECORATE_NONE);
            if ($milestone instanceof entities\Milestone)
            {
                $datasets = array();

                $burndown_data = $milestone->getBurndownData();

                if (count($burndown_data['estimations']['hours']))
                {
                    foreach ($burndown_data['estimations']['hours'] as $key => $e)
                    {
                        if (array_key_exists($key, $burndown_data['spent_times']['hours']))
                        {
                            $burndown_data['estimations']['hours'][$key] -= $burndown_data['spent_times']['hours'][$key];
                            if ($burndown_data['estimations']['hours'][$key] > $maxEstimation)
                                $maxEstimation = $burndown_data['estimations']['hours'][$key];
                        }
                    }
                    $datasets[] = array('values' => array_values($burndown_data['estimations']['hours']), 'label' => framework\Context::getI18n()->__('Remaining effort'), 'burndown' => array('maxEstimation' => $maxEstimation, 'label' => "Burndown Line"));
                    $this->labels = array_keys($burndown_data['estimations']['hours']);
                }
                else
                {
                    $datasets[] = array('values' => array(0), 'label' => framework\Context::getI18n()->__('Remaining effort'), 'burndown' => array('maxEstimation' => $maxEstimation, 'label' => "Burndown Line"));
                    $this->labels = array(0);
                }
                $this->datasets = $datasets;
                $this->milestone = $milestone;
            }
            else
            {
                return $this->renderText('');
            }
        }

        /**
         * Set color on a user story
         *
         * @param framework\Request $request
         */
        public function runScrumSetStoryDetail(framework\Request $request)
        {
            $this->forward403if(framework\Context::getCurrentProject()->isArchived());
            $this->forward403unless($this->_checkProjectPageAccess('project_scrum'));
            $issue = entities\Issue::getB2DBTable()->selectById((int) $request['story_id']);
            if ($issue instanceof entities\Issue)
            {
                switch ($request['detail'])
                {
                    case 'color':
                        $issue->setAgileColor($request['color']);
                        $issue->save();
                        return $this->renderJSON(array('failed' => false));
                        break;
                }
            }
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Invalid user story')));
        }

        /**
         * Assign a user story to a release id
         *
         * @param framework\Request $request
         */
        public function runAssignRelease(framework\Request $request)
        {
            try
            {
                $issue = entities\Issue::getB2DBTable()->selectById((int) $request['issue_id']);
                $release = tables\Builds::getTable()->selectById((int) $request['release_id']);

                $issue->addAffectedBuild($release);

                return $this->renderJSON(array('issue_id' => $issue->getID(), 'release_id' => $release->getID(), 'closed_pct' => $release->getPercentComplete()));
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured when trying to assign the issue to the release')));
            }
        }

        /**
         * Assign an issue to an epic
         *
         * @param framework\Request $request
         */
        public function runAssignEpic(framework\Request $request)
        {
            try
            {
                $epic = entities\Issue::getB2DBTable()->selectById((int) $request['epic_id']);
                $issue = entities\Issue::getB2DBTable()->selectById((int) $request['issue_id']);

                $epic->addChildIssue($issue);

                return $this->renderJSON(array('issue_id' => $issue->getID(), 'epic_id' => $epic->getID(), 'closed_pct' => $epic->getEstimatedPercentCompleted(), 'num_child_issues' => $epic->countChildIssues(), 'estimate' => entities\Issue::getFormattedTime($epic->getEstimatedTime())));
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured when trying to assign the issue to the release')));
            }
        }

        /**
         * Assign a user story to a milestone id
         *
         * @param framework\Request $request
         */
        public function runAssignMilestone(framework\Request $request)
        {
            $this->forward403if(framework\Context::getCurrentProject()->isArchived());
            $this->forward403unless($this->_checkProjectPageAccess('project_scrum') && framework\Context::getUser()->canAssignScrumUserStories($this->selected_project));
            try
            {
                $issue = entities\Issue::getB2DBTable()->selectById((int) $request['issue_id']);
                $new_milestone_id = (int) $request['milestone_id'];
                try
                {
                    $new_milestone = tables\Milestones::getTable()->selectById($new_milestone_id);
                    if ($issue instanceof entities\Issue)
                    {
                        $old_milestone = $issue->getMilestone();
                        $issue->setMilestone($new_milestone);
                        $issue->save();
                        foreach ($issue->getChildIssues() as $child_issue)
                        {
                            $child_issue->setMilestone($new_milestone);
                            $child_issue->save();
                        }
                        $new_issues = ($new_milestone instanceof entities\Milestone) ? $new_milestone->countIssues() : 0;
                        $new_e_points = ($new_milestone instanceof entities\Milestone) ? $new_milestone->getPointsEstimated() : 0;
                        $new_e_hours = ($new_milestone instanceof entities\Milestone) ? $new_milestone->getHoursEstimated() : 0;
                        return $this->renderJSON(array('issue_id' => $issue->getID(), 'issues' => $new_issues, 'points' => $new_e_points, 'hours' => $new_e_hours));
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured when trying to assign the issue to the new milestone')));
                }
            }
            catch (\Exception $e)
            {
                return $this->renderJSON(array('failed' => true, 'error' => $e->getMessage()));
            }
        }

        /**
         * Add a new sprint type milestone to a project
         *
         * @param framework\Request $request
         */
        public function runScrumAddSprint(framework\Request $request)
        {
            $this->forward403if(framework\Context::getCurrentProject()->isArchived());
            $this->forward403unless($this->_checkProjectPageAccess('project_scrum'));
            if (($sprint_name = $request['sprint_name']) && trim($sprint_name) != '')
            {
                $sprint = new entities\Milestone();
                $sprint->setName($sprint_name);
                $sprint->setType(entities\Milestone::TYPE_SCRUMSPRINT);
                $sprint->setProject($this->selected_project);
                $sprint->setStartingDate(mktime(0, 0, 1, $request['starting_month'], $request['starting_day'], $request['starting_year']));
                $sprint->setScheduledDate(mktime(23, 59, 59, $request['scheduled_month'], $request['scheduled_day'], $request['scheduled_year']));
                $sprint->save();
                return $this->renderJSON(array('failed' => false, 'content' => $this->getComponentHTML('sprintbox', array('sprint' => $sprint)), 'sprint_id' => $sprint->getID()));
            }
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Please specify a sprint name')));
        }

        /**
         * The project issue list page
         *
         * @param framework\Request $request
         */
        public function runIssues(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_issues'));
        }

        /**
         * The project team page
         *
         * @param framework\Request $request
         */
        public function runTeam(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_team'));
            $this->assigned_users = $this->selected_project->getAssignedUsers();
            $this->assigned_teams = $this->selected_project->getAssignedTeams();
        }

        /**
         * The project statistics page
         *
         * @param framework\Request $request
         */
        public function runStatistics(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_statistics'));
        }

        public function runStatisticsLast15(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_statistics'));

            if (!function_exists('imagecreatetruecolor'))
            {
                return $this->return404(framework\Context::getI18n()->__('The libraries to generate images are not installed. Please see http://www.thebuggenie.com for more information'));
            }

            $this->getResponse()->setContentType('image/png');
            $this->getResponse()->setDecoration(framework\Response::DECORATE_NONE);
            $datasets = array();
            $issues = $this->selected_project->getLast15Counts();
            $datasets[] = array('values' => $issues['open'], 'label' => framework\Context::getI18n()->__('Open issues', array(), true));
            $datasets[] = array('values' => $issues['closed'], 'label' => framework\Context::getI18n()->__('Issues closed', array(), true));
            $this->datasets = $datasets;
            $this->labels = array(15, '', '', '', '', 10, '', '', '', '', 5, '', '', '', '', 0);
        }

        public function runStatisticsImagesets(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_statistics'));
            try
            {
                if (!in_array($request['set'], array('issues_per_status', 'issues_per_state', 'issues_per_priority', 'issues_per_category', 'issues_per_resolution', 'issues_per_reproducability')))
                {
                    throw new \InvalidArgumentException(framework\Context::getI18n()->__('Invalid image set'));
                }

                $base_url = framework\Context::getRouting()->generate('project_statistics_image', array('project_key' => $this->selected_project->getKey(), 'key' => '%key', 'mode' => '%mode', 'image_number' => '%image_number'));
                $key = urlencode('%key');
                $mode = urlencode('%mode');
                $image_number = urlencode('%image_number');
                $set = $request['set'];
                if ($set != 'issues_per_state')
                {
                    $images = array('main' => str_replace(array($key, $mode, $image_number), array($set, 'main', 1), $base_url),
                        'mini_1_small' => str_replace(array($key, $mode, $image_number), array($set, 'mini', 1), $base_url),
                        'mini_1_large' => str_replace(array($key, $mode, $image_number), array($set, 'main', 1), $base_url),
                        'mini_2_small' => str_replace(array($key, $mode, $image_number), array($set, 'mini', 2), $base_url),
                        'mini_2_large' => str_replace(array($key, $mode, $image_number), array($set, 'main', 2), $base_url),
                        'mini_3_small' => str_replace(array($key, $mode, $image_number), array($set, 'mini', 3), $base_url),
                        'mini_3_large' => str_replace(array($key, $mode, $image_number), array($set, 'main', 3), $base_url));
                }
                else
                {
                    $images = array('main' => str_replace(array($key, $mode, $image_number), array($set, 'main', 1), $base_url));
                }
                $this->getResponse()->setHttpStatus(200);
                return $this->renderJSON(array('images' => $images));
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $e->getMessage()));
            }
        }

        protected function _calculateImageDetails($counts)
        {
            $i18n = framework\Context::getI18n();
            $labels = array();
            $values = array();
            $colors = array();
            foreach ($counts as $item_id => $details)
            {
                if ($this->image_number == 1)
                {
                    $value = $details['open'] + $details['closed'];
                }
                if ($this->image_number == 2)
                {
                    $value = $details['open'];
                }
                if ($this->image_number == 3)
                {
                    $value = $details['closed'];
                }
                if (isset($value) && $value > 0)
                {
                    if ($item_id != 0 || $this->key == 'issues_per_state')
                    {
                        switch ($this->key)
                        {
                            case 'issues_per_status':
                                $item = entities\Status::getB2DBTable()->selectById($item_id);
                                break;
                            case 'issues_per_priority':
                                $item = entities\Priority::getB2DBTable()->selectById($item_id);
                                break;
                            case 'issues_per_category':
                                $item = entities\Category::getB2DBTable()->selectById($item_id);
                                break;
                            case 'issues_per_resolution':
                                $item = entities\Resolution::getB2DBTable()->selectById($item_id);
                                break;
                            case 'issues_per_reproducability':
                                $item = entities\Reproducability::getB2DBTable()->selectById($item_id);
                                break;
                            case 'issues_per_state':
                                $item = ($item_id == entities\Issue::STATE_OPEN) ? $i18n->__('Open', array(), true) : $i18n->__('Closed', array(), true);
                                break;
                            default:
                                $item = null;
                        }
                        if ($this->key != 'issues_per_state')
                        {
                            $labels[] = ($item instanceof entities\Datatype) ? html_entity_decode($item->getName()) : $i18n->__('Unknown', array(), true);
                            framework\Context::loadLibrary('common');
                            if ($item instanceof entities\Status)
                                $colors[] = tbg_hex_to_rgb($item->getColor());
                        }
                        else
                        {
                            $labels[] = $item;
                        }
                    }
                    else
                    {
                        $labels[] = $i18n->__('Not determined', array(), true);
                    }
                    $values[] = $value;
                }
            }

            return array($values, $labels, $colors);
        }

        protected function _generateImageDetailsFromKey($mode = null)
        {
            $this->graphmode = null;
            $i18n = framework\Context::getI18n();
            if ($mode == 'main')
            {
                $this->width = 695;
                $this->height = 310;
            }
            else
            {
                $this->width = 230;
                $this->height = 150;
            }
            switch ($this->key)
            {
                case 'issues_per_status':
                    $this->graphmode = 'piechart';
                    $counts = tables\Issues::getTable()->getStatusCountByProjectID($this->selected_project->getID());
                    if ($this->image_number == 1)
                    {
                        $this->title = $i18n->__('Total number of issues per status type');
                    }
                    elseif ($this->image_number == 2)
                    {
                        $this->title = $i18n->__('Open issues per status type');
                    }
                    elseif ($this->image_number == 3)
                    {
                        $this->title = $i18n->__('Closed issues per status type');
                    }
                    break;
                case 'issues_per_priority':
                    $this->graphmode = 'piechart';
                    $counts = tables\Issues::getTable()->getPriorityCountByProjectID($this->selected_project->getID());
                    if ($this->image_number == 1)
                    {
                        $this->title = $i18n->__('Total number of issues per priority level');
                    }
                    elseif ($this->image_number == 2)
                    {
                        $this->title = $i18n->__('Open issues per priority level');
                    }
                    elseif ($this->image_number == 3)
                    {
                        $this->title = $i18n->__('Closed issues per priority level');
                    }
                    break;
                case 'issues_per_category':
                    $this->graphmode = 'piechart';
                    $counts = tables\Issues::getTable()->getCategoryCountByProjectID($this->selected_project->getID());
                    if ($this->image_number == 1)
                    {
                        $this->title = $i18n->__('Total number of issues per category');
                    }
                    elseif ($this->image_number == 2)
                    {
                        $this->title = $i18n->__('Open issues per category');
                    }
                    elseif ($this->image_number == 3)
                    {
                        $this->title = $i18n->__('Closed issues per category');
                    }
                    break;
                case 'issues_per_resolution':
                    $this->graphmode = 'piechart';
                    $counts = tables\Issues::getTable()->getResolutionCountByProjectID($this->selected_project->getID());
                    if ($this->image_number == 1)
                    {
                        $this->title = $i18n->__('Total number of issues per resolution');
                    }
                    elseif ($this->image_number == 2)
                    {
                        $this->title = $i18n->__('Open issues per resolution');
                    }
                    elseif ($this->image_number == 3)
                    {
                        $this->title = $i18n->__('Closed issues per resolution');
                    }
                    break;
                case 'issues_per_reproducability':
                    $this->graphmode = 'piechart';
                    $counts = tables\Issues::getTable()->getReproducabilityCountByProjectID($this->selected_project->getID());
                    if ($this->image_number == 1)
                    {
                        $this->title = $i18n->__('Total number of issues per reproducability level');
                    }
                    elseif ($this->image_number == 2)
                    {
                        $this->title = $i18n->__('Open issues per reproducability level');
                    }
                    elseif ($this->image_number == 3)
                    {
                        $this->title = $i18n->__('Closed issues per reproducability level');
                    }
                    break;
                case 'issues_per_state':
                    $this->graphmode = 'piechart';
                    $counts = tables\Issues::getTable()->getStateCountByProjectID($this->selected_project->getID());
                    if ($this->image_number == 1)
                    {
                        $this->title = $i18n->__('Total number of issues (open / closed)');
                    }
                    break;
                default:
                    throw new \Exception(__("unknown key '%key'", array('%key' => $this->key)));
            }
            $this->title = html_entity_decode($this->title);
            list ($values, $labels, $colors) = $this->_calculateImageDetails($counts);
            $this->values = $values;
            $this->labels = $labels;
            $this->colors = $colors;
        }

        public function runStatisticsGetImage(framework\Request $request)
        {
            $this->forward403unless($this->_checkProjectPageAccess('project_statistics'));

            if (!function_exists('imagecreatetruecolor'))
            {
                return $this->return404(framework\Context::getI18n()->__('The libraries to generate images are not installed. Please see http://www.thebuggenie.com for more information'));
            }

            $this->getResponse()->setContentType('image/png');
            $this->getResponse()->setDecoration(framework\Response::DECORATE_NONE);

            $this->key = $request['key'];
            $this->image_number = (int) $request['image_number'];
            $this->_generateImageDetailsFromKey($request['mode']);
        }

        public function runListIssues(framework\Request $request)
        {
            $filters = array('project_id' => array('operator' => '=', 'value' => $this->selected_project->getID()));
            $filter_state = $request->getParameter('state', 'all');
            $filter_issuetype = $request->getParameter('issuetype', 'all');
            $filter_assigned_to = $request->getParameter('assigned_to', 'all');

            if (mb_strtolower($filter_state) != 'all')
            {
                $filters['state'] = array('operator' => '=', 'value' => '');
                if (mb_strtolower($filter_state) == 'open')
                    $filters['state']['value'] = entities\Issue::STATE_OPEN;
                elseif (mb_strtolower($filter_state) == 'closed')
                    $filters['state']['value'] = entities\Issue::STATE_CLOSED;
            }

            if (mb_strtolower($filter_issuetype) != 'all')
            {
                $issuetype = entities\Issuetype::getByKeyish($filter_issuetype);
                if ($issuetype instanceof entities\Issuetype)
                {
                    $filters['issuetype'] = array('operator' => '=', 'value' => $issuetype->getID());
                }
            }

            if (mb_strtolower($filter_assigned_to) != 'all')
            {
                $user_id = 0;
                switch (mb_strtolower($filter_assigned_to))
                {
                    case 'me':
                        $user_id = framework\Context::getUser()->getID();
                        break;
                    case 'none':
                        $user_id = 0;
                        break;
                    default:
                        try
                        {
                            $user = entities\User::findUser(mb_strtolower($filter_assigned_to));
                            if ($user instanceof entities\User)
                                $user_id = $user->getID();
                        }
                        catch (\Exception $e)
                        {

                        }
                        break;
                }

                $filters['assignee_user'] = array('operator' => '=', 'value' => $user_id);
            }

            list ($this->issues, $this->count) = entities\Issue::findIssues($filters, 0);
            $this->return_issues = array();
        }

        public function runListWorkflowTransitions(framework\Request $request)
        {
            $i18n = framework\Context::getI18n();
            $issue = entities\Issue::getIssueFromLink($request['issue_no']);
            if ($issue->getProject()->getID() != $this->selected_project->getID())
            {
                throw new \Exception($i18n->__('This issue is not valid for this project'));
            }
            $transitions = array();
            foreach ($issue->getAvailableWorkflowTransitions() as $transition)
            {
                if (!$transition instanceof entities\WorkflowTransition)
                    continue;
                $details = array('name' => $transition->getName(), 'description' => $transition->getDescription(), 'template' => $transition->getTemplate());
                if ($details['template'])
                {
                    $details['post_validation'] = array();
                    foreach ($transition->getPostValidationRules() as $rule)
                    {
                        $details['post_validation'][] = array('name' => $rule->getRule(), 'values' => $rule->getRuleValueAsJoinedString());
                    }
                }
                $transitions[] = $details;
            }
            $this->transitions = $transitions;
        }

        public function runUpdateIssueDetails(framework\Request $request)
        {
            $this->forward403if(framework\Context::getCurrentProject()->isArchived());
            $this->error = false;
            try
            {
                $i18n = framework\Context::getI18n();
                $issue = entities\Issue::getIssueFromLink($request['issue_no']);
                if ($issue->getProject()->getID() != $this->selected_project->getID())
                {
                    throw new \Exception($i18n->__('This issue is not valid for this project'));
                }
                if (!$issue instanceof entities\Issue)
                    die();

                $workflow_transition = null;
                if ($passed_transition = $request['workflow_transition'])
                {
                    //echo "looking for transition ";
                    $key = str_replace(' ', '', mb_strtolower($passed_transition));
                    //echo $key . "\n";
                    foreach ($issue->getAvailableWorkflowTransitions() as $transition)
                    {
                        //echo str_replace(' ', '', mb_strtolower($transition->getName())) . "?";
                        if (mb_strpos(str_replace(' ', '', mb_strtolower($transition->getName())), $key) !== false)
                        {
                            $workflow_transition = $transition;
                            //echo "found transition " . $transition->getID();
                            break;
                        }
                        //echo "no";
                    }

                    if (!$workflow_transition instanceof entities\WorkflowTransition)
                        throw new \Exception("This transition ({$key}) is not valid");
                }
                $fields = $request->getRawParameter('fields', array());
                $return_values = array();
                if ($workflow_transition instanceof entities\WorkflowTransition)
                {
                    foreach ($fields as $field_key => $field_value)
                    {
                        $classname = "\\thebuggenie\\core\\entities\\" . ucfirst($field_key);
                        $method = "set" . ucfirst($field_key);
                        $choices = $classname::getAll();
                        $found = false;
                        foreach ($choices as $choice_key => $choice)
                        {
                            if (mb_strpos(str_replace(' ', '', mb_strtolower($choice->getName())), str_replace(' ', '', mb_strtolower($field_value))) !== false)
                            {
                                $request->setParameter($field_key . '_id', $choice->getId());
                                break;
                            }
                        }
                    }
                    $request->setParameter('comment_body', $request['message']);
                    $return_values['applied_transition'] = $workflow_transition->getName();
                    if ($workflow_transition->validateFromRequest($request))
                    {
                        $retval = $workflow_transition->transitionIssueToOutgoingStepFromRequest($issue, $request);
                        $return_values['transition_ok'] = ($retval === false) ? false : true;
                    }
                    else
                    {
                        $return_values['transition_ok'] = false;
                        $return_values['message'] = "Please pass all information required for this transition";
                    }
                }
                elseif ($issue->isUpdateable())
                {
                    foreach ($fields as $field_key => $field_value)
                    {
                        try
                        {
                            if (in_array($field_key, array_merge(array('title', 'state'), entities\Datatype::getAvailableFields(true))))
                            {
                                switch ($field_key)
                                {
                                    case 'state':
                                        $issue->setState(($field_value == 'open') ? entities\Issue::STATE_OPEN : entities\Issue::STATE_CLOSED);
                                        break;
                                    case 'title':
                                        if ($field_value != '')
                                            $issue->setTitle($field_value);
                                        else
                                            throw new \Exception($i18n->__('Invalid title'));
                                        break;
                                    case 'shortname':
                                    case 'description':
                                    case 'reproduction_steps':
                                        $method = "set" . ucfirst($field_key);
                                        $issue->$method($field_value);
                                        break;
                                    case 'status':
                                    case 'resolution':
                                    case 'reproducability':
                                    case 'priority':
                                    case 'severity':
                                    case 'category':
                                        $classname = "\\thebuggenie\\core\\entities\\" . ucfirst($field_key);
                                        $method = "set" . ucfirst($field_key);
                                        $choices = $classname::getAll();
                                        $found = false;
                                        foreach ($choices as $choice_key => $choice)
                                        {
                                            if (str_replace(' ', '', mb_strtolower($choice->getName())) == str_replace(' ', '', mb_strtolower($field_value)))
                                            {
                                                $issue->$method($choice);
                                                $found = true;
                                            }
                                        }
                                        if (!$found)
                                        {
                                            throw new \Exception('Could not find this value');
                                        }
                                        break;
                                    case 'percent_complete':
                                        $issue->setPercentCompleted($field_value);
                                        break;
                                    case 'owner':
                                    case 'assignee':
                                        $set_method = "set" . ucfirst($field_key);
                                        $unset_method = "un{$set_method}";
                                        switch (mb_strtolower($field_value))
                                        {
                                            case 'me':
                                                $issue->$set_method(framework\Context::getUser());
                                                break;
                                            case 'none':
                                                $issue->$unset_method();
                                                break;
                                            default:
                                                try
                                                {
                                                    $user = entities\User::findUser(mb_strtolower($field_value));
                                                    if ($user instanceof entities\User)
                                                        $issue->$set_method($user);
                                                }
                                                catch (\Exception $e)
                                                {
                                                    throw new \Exception('No such user found');
                                                }
                                                break;
                                        }
                                        break;
                                    case 'estimated_time':
                                    case 'spent_time':
                                        $set_method = "set" . ucfirst(str_replace('_', '', $field_key));
                                        $issue->$set_method($field_value);
                                        break;
                                    case 'milestone':
                                        $found = false;
                                        foreach ($this->selected_project->getMilestones() as $milestone)
                                        {
                                            if (str_replace(' ', '', mb_strtolower($milestone->getName())) == str_replace(' ', '', mb_strtolower($field_value)))
                                            {
                                                $issue->setMilestone($milestone->getID());
                                                $found = true;
                                            }
                                        }
                                        if (!$found)
                                        {
                                            throw new \Exception('Could not find this milestone');
                                        }
                                        break;
                                    default:
                                        throw new \Exception($i18n->__('Invalid field'));
                                }
                            }
                            $return_values[$field_key] = array('success' => true);
                        }
                        catch (\Exception $e)
                        {
                            $return_values[$field_key] = array('success' => false, 'error' => $e->getMessage());
                        }
                    }
                }

                if (!$workflow_transition instanceof entities\WorkflowTransition)
                    $issue->getWorkflow()->moveIssueToMatchingWorkflowStep($issue);

                if (!array_key_exists('transition_ok', $return_values) || $return_values['transition_ok'])
                {
                    $comment = new entities\Comment();
                    $comment->setContent($request->getParameter('message', null, false));
                    $comment->setPostedBy(framework\Context::getUser()->getID());
                    $comment->setTargetID($issue->getID());
                    $comment->setTargetType(entities\Comment::TYPE_ISSUE);
                    $comment->setModuleName('core');
                    $comment->setIsPublic(true);
                    $comment->setSystemComment(false);
                    $comment->save();
                    $issue->setSaveComment($comment);
                    $issue->save();
                }

                $this->return_values = $return_values;
            }
            catch (\Exception $e)
            {
                //$this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('failed' => true, 'error' => $e->getMessage()));
            }
        }

        public function runGetMilestoneRoadmapIssues(framework\Request $request)
        {
            try
            {
                $i18n = framework\Context::getI18n();
                if ($request->hasParameter('milestone_id'))
                {
                    $milestone = tables\Milestones::getTable()->selectById($request['milestone_id']);
                    return $this->renderJSON(array('content' => $this->getComponentHTML('project/milestoneissues', array('milestone' => $milestone))));
                }
                else
                {
                    throw new \Exception($i18n->__('Invalid milestone'));
                }
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $e->getMessage()));
            }
        }

        public function runMilestoneIssues(framework\Request $request)
        {
            try
            {
                $i18n = framework\Context::getI18n();
                if ($request->getParameter('milestone_id'))
                {
                    $milestone = tables\Milestones::getTable()->selectById($request['milestone_id']);
                }
                $board = ($request['board_id']) ? tables\AgileBoards::getTable()->selectById($request['board_id']) : new entities\AgileBoard();
                if ($request->isPost())
                {
                    $issue_table = tables\Issues::getTable();
                    $orders = array_keys($request["issue_ids"]);
                    foreach ($request["issue_ids"] as $issue_id)
                    {
                        $issue_table->setOrderByIssueId(array_pop($orders), $issue_id);
                    }
                    return $this->renderJSON(array('sorted' => 'ok'));
                }
                elseif (isset($milestone) && $milestone instanceof entities\Milestone)
                {
                    return $this->renderJSON(array('content' => $this->getComponentHTML('project/planning_milestoneissues', array('milestone' => $milestone, 'board' => $board))));
                }
                else
                {
                    return $this->renderJSON(array('content' => $this->getComponentHTML('project/agileboardbacklog', array('board' => $board))));
                }
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $e->getMessage()));
            }
        }

        public function runGetMilestoneDetails(framework\Request $request)
        {
            try
            {
                $i18n = framework\Context::getI18n();
                if ($request->hasParameter('milestone_id'))
                {
                    $milestone = entities\Milestone::getB2DBTable()->selectById($request['milestone_id']);
                    $details = array('failed' => false);
                    $details['percent'] = $milestone->getPercentComplete();
                    $details['date_string'] = $milestone->getDateString();
                    if ($milestone->isSprint())
                    {
                        $details['closed_points'] = $milestone->getPointsSpent();
                        $details['assigned_points'] = $milestone->getPointsEstimated();
                    }
                    $details['closed_issues'] = $milestone->countClosedIssues();
                    $details['assigned_issues'] = $milestone->countIssues();
                    return $this->renderJSON($details);
                }
                else
                {
                    throw new \Exception($i18n->__('Invalid milestone'));
                }
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('failed' => true, 'error' => $e->getMessage()));
            }
        }

        public function runGetMilestone(framework\Request $request)
        {
            $milestone = new entities\Milestone($request['milestone_id']);
            return $this->renderJSON(array('content' => framework\Action::returnComponentHTML('project/milestonebox', array('milestone' => $milestone)), 'milestone_id' => $milestone->getID(), 'milestone_name' => $milestone->getName(), 'milestone_order' => array_keys($milestone->getProject()->getMilestonesForRoadmap())));
        }

        public function runMarkMilestoneFinished(framework\Request $request)
        {
            try
            {
                if (!($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project)))
                {
                    throw new \Exception("You don't have access to modify milestones");
                }
                $return_options = array('finished' => 'ok');
                $board = tables\AgileBoards::getTable()->selectById($request['board_id']);
                $milestone = entities\Milestone::getB2DBTable()->selectById($request['milestone_id']);
                $reached_date = mktime(23, 59, 59, framework\Context::getRequest()->getParameter('milestone_finish_reached_month'), framework\Context::getRequest()->getParameter('milestone_finish_reached_day'), framework\Context::getRequest()->getParameter('milestone_finish_reached_year'));
                $milestone->setReachedDate($reached_date);
                $milestone->setReached();
                $milestone->setClosed(true);
                $milestone->save();
                if ($request->hasParameter('unresolved_issues_action'))
                {
                    switch ($request['unresolved_issues_action'])
                    {
                        case 'backlog':
                            tables\Issues::getTable()->reAssignIssuesByMilestoneIds($milestone->getID(), null, 0);
                            break;
                        case 'reassign':
                            $new_milestone = entities\Milestone::getB2DBTable()->selectById($request['assign_issues_milestone_id']);
                            $return_options['new_milestone_id'] = $new_milestone->getID();
                            break;
                        case 'addnew':
                            $new_milestone = $this->_saveMilestoneDetails($request);
                            $return_options['component'] = $this->getComponentHTML('milestonebox', array('milestone' => $new_milestone, 'board' => $board));
                            $return_options['new_milestone_id'] = $new_milestone->getID();
                            break;
                    }
                    if (isset($new_milestone) && $new_milestone instanceof entities\Milestone)
                    {
                        tables\Issues::getTable()->reAssignIssuesByMilestoneIds($milestone->getID(), $new_milestone->getID());
                    }
                }

                return $this->renderJSON($return_options);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $e->getMessage()));
            }
        }

        public function runRemoveMilestone(framework\Request $request)
        {
            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                $milestone = new entities\Milestone($request['milestone_id']);
                $no_milestone = new entities\Milestone(0);
                $no_milestone->setProject($milestone->getProject());
                $milestone->delete();
                return $this->renderJSON(array('issue_count' => $no_milestone->countIssues(), 'hours' => $no_milestone->getHoursEstimated(), 'points' => $no_milestone->getPointsEstimated()));
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $this->getI18n()->__("You don't have access to modify milestones")));
        }

        protected function _saveMilestoneDetails(framework\Request $request, $milestone_id = null)
        {
            if (!$request['name'])
                throw new \Exception($this->getI18n()->__('You must provide a valid milestone name'));

            $milestone = new entities\Milestone($milestone_id);
            $milestone->setName($request['name']);
            $milestone->setProject($this->selected_project);
            $milestone->setStarting((bool) $request['is_starting']);
            $milestone->setScheduled((bool) $request['is_scheduled']);
            $milestone->setDescription($request['description']);
            $milestone->setVisibleRoadmap($request['visibility_roadmap']);
            $milestone->setVisibleIssues($request['visibility_issues']);
            $milestone->setType($request->getParameter('milestone_type', entities\Milestone::TYPE_REGULAR));
            if ($request->hasParameter('sch_month') && $request->hasParameter('sch_day') && $request->hasParameter('sch_year'))
            {
                $scheduled_date = mktime(23, 59, 59, framework\Context::getRequest()->getParameter('sch_month'), framework\Context::getRequest()->getParameter('sch_day'), framework\Context::getRequest()->getParameter('sch_year'));
                $milestone->setScheduledDate($scheduled_date);
            }
            else
                $milestone->setScheduledDate(0);

            if ($request->hasParameter('starting_month') && $request->hasParameter('starting_day') && $request->hasParameter('starting_year'))
            {
                $starting_date = mktime(0, 0, 1, framework\Context::getRequest()->getParameter('starting_month'), framework\Context::getRequest()->getParameter('starting_day'), framework\Context::getRequest()->getParameter('starting_year'));
                $milestone->setStartingDate($starting_date);
            }
            else
                $milestone->setStartingDate(0);

            $milestone->save();

            return $milestone;
        }

        public function runMilestone(framework\Request $request)
        {
            if ($request->isPost())
            {
                if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
                {
                    try
                    {
                        $milestone = $this->_saveMilestoneDetails($request, $request['milestone_id']);
                        $board = tables\AgileBoards::getTable()->selectById($request['board_id']);

                        if ($request->hasParameter('issues') && $request['include_selected_issues'])
                        {
                            tables\Issues::getTable()->assignMilestoneIDbyIssueIDs($milestone->getID(), $request['issues']);
                        }

                        $message = framework\Context::getI18n()->__('Milestone saved');
                        return $this->renderJSON(array('message' => $message, 'component' => $this->getComponentHTML('milestonebox', array('milestone' => $milestone, 'board' => $board)), 'milestone_id' => $milestone->getID()));
                    }
                    catch (\Exception $e)
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => $e->getMessage()));
                    }
                }
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array("error" => $this->getI18n()->__("You don't have access to modify milestones")));
            }
        }

        public function runMenuLinks(framework\Request $request)
        {

        }

        public function runTransitionIssue(framework\Request $request)
        {
            try
            {
                $transition = entities\WorkflowTransition::getB2DBTable()->selectById($request['transition_id']);
                $issue = entities\Issue::getB2DBTable()->selectById((int) $request['issue_id']);
                if (!$issue->isWorkflowTransitionsAvailable())
                {
                    throw new \Exception(framework\Context::getI18n()->__('You are not allowed to perform any workflow transitions on this issue'));
                }

                if ($transition->validateFromRequest($request))
                {
                    $transition->transitionIssueToOutgoingStepFromRequest($issue, $request);
                }
                else
                {
                    framework\Context::setMessage('issue_error', 'transition_error');
                    framework\Context::setMessage('issue_workflow_errors', $transition->getValidationErrors());
                }
                $this->forward(framework\Context::getRouting()->generate('viewissue', array('project_key' => $issue->getProject()->getKey(), 'issue_no' => $issue->getFormattedIssueNo())));
            }
            catch (\Exception $e)
            {
                return $this->return404();
            }
        }

        public function runTransitionIssues(framework\Request $request)
        {
            try
            {
                try
                {
                    $transition = entities\WorkflowTransition::getB2DBTable()->selectById($request['transition_id']);
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => $this->getI18n()->__('This is not a valid transition')));
                }
                $issue_ids = $request['issue_ids'];
                $status = null;
                $closed = false;
                foreach ($issue_ids as $issue_id)
                {
                    $issue = entities\Issue::getB2DBTable()->selectById((int) $issue_id);
                    if (!$issue->isWorkflowTransitionsAvailable() || !$transition->validateFromRequest($request))
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('The transition could not be applied to issue %issue_number because of %errors', array('%issue_number' => $issue->getFormattedIssueNo(), '%errors' => join(', ', $transition->getValidationErrors())))));
                    }

                    try
                    {
                        $transition->transitionIssueToOutgoingStepFromRequest($issue, $request);
                    }
                    catch (\Exception $e)
                    {
                        $this->getResponse()->setHttpStatus(400);
                        framework\Logging::log(framework\Logging::LEVEL_WARNING, 'Transition ' . $transition->getID() . ' failed for issue ' . $issue_id);
                        framework\Logging::log(framework\Logging::LEVEL_WARNING, $e->getMessage());
                        return $this->renderJSON(array('error' => $this->getI18n()->__('The transition failed because of an error in the workflow. Check your workflow configuration.')));
                    }
                    if ($status === null)
                        $status = $issue->getStatus();

                    $closed = $issue->isClosed();
                }

                framework\Context::loadLibrary('common');
                $options = array('issue_ids' => array_keys($issue_ids), 'last_updated' => tbg_formatTime(time(), 20), 'closed' => $closed);
                $options['status'] = array('color' => $status->getColor(), 'name' => $status->getName(), 'id' => $status->getID());
                if ($request->hasParameter('milestone_id'))
                {
                    $milestone = new entities\Milestone($request['milestone_id']);
                    $options['milestone_id'] = $milestone->getID();
                    $options['milestone_name'] = $milestone->getName();
                }
                foreach (array('resolution', 'priority', 'category', 'severity') as $item)
                {
                    $class = "\\thebuggenie\\core\\entities\\" . ucfirst($item);
                    if ($request->hasParameter($item . '_id'))
                    {
                        if ($item_id = $request[$item . '_id'])
                        {
                            $itemobject = new $class($item_id);
                            $itemname = $itemobject->getName();
                        }
                        else
                        {
                            $item_id = 0;
                            $itemname = '-';
                        }
                        $options[$item] = array('name' => $itemname, 'id' => $item_id);
                    }
                    else
                    {
                        $method = 'get' . ucfirst($item);
                        $itemname = ($issue->$method() instanceof $class) ? $issue->$method()->getName() : '-';
                        $item_id = ($issue->$method() instanceof $class) ? $issue->$method()->getID() : 0;
                        $options[$item] = array('name' => $itemname, 'id' => $item_id);
                    }
                }

                return $this->renderJSON($options);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                framework\Logging::log(framework\Logging::LEVEL_WARNING, $e->getMessage());
                return $this->renderJSON(array('error' => $this->getI18n()->__('An error occured when trying to apply the transition')));
            }
        }

        public function runSettings(framework\Request $request)
        {
            $this->forward403if(framework\Context::getCurrentProject()->isArchived() || !$this->getUser()->canEditProjectDetails(framework\Context::getCurrentProject()));
            $this->settings_saved = framework\Context::getMessageAndClear('project_settings_saved');
        }

        public function runReleaseCenter(framework\Request $request)
        {
            $this->forward403if(framework\Context::getCurrentProject()->isArchived() || !$this->getUser()->canManageProjectReleases(framework\Context::getCurrentProject()));
            $this->build_error = framework\Context::getMessageAndClear('build_error');
        }

        public function runReleases(framework\Request $request)
        {
            $this->_setupBuilds();
        }

        protected function _setupBuilds()
        {
            $builds = $this->selected_project->getBuilds();

            $active_builds = array(0 => array());
            $archived_builds = array(0 => array());

            foreach ($this->selected_project->getEditions() as $edition_id => $edition)
            {
                $active_builds[$edition_id] = array();
                $archived_builds[$edition_id] = array();
            }

            foreach ($builds as $build)
            {
                if ($build->isLocked())
                    $archived_builds[$build->getEditionID()][$build->getID()] = $build;
                else
                    $active_builds[$build->getEditionID()][$build->getID()] = $build;
            }

            $this->active_builds = $active_builds;
            $this->archived_builds = $archived_builds;
        }

        /**
         * Find users and show selection box
         *
         * @param framework\Request $request The request object
         */
        public function runFindAssignee(framework\Request $request)
        {
            $this->forward403unless($request->isPost());

            $this->message = false;

            if ($request['find_by'])
            {
                $this->selected_project = entities\Project::getB2DBTable()->selectById($request['project_id']);
                $this->users = tables\Users::getTable()->getByDetails($request['find_by'], 10);
                $this->teams = tables\Teams::getTable()->quickfind($request['find_by']);
                $this->global_roles = entities\Role::getAll();
                $this->project_roles = entities\Role::getByProjectID($this->selected_project->getID());
            }
            else
            {
                $this->message = true;
            }
        }

        /**
         * Adds a user or team to a project
         *
         * @param framework\Request $request The request object
         */
        public function runAssignToProject(framework\Request $request)
        {
            $this->forward403unless($request->isPost());

            if ($this->getUser()->canEditProjectDetails($this->selected_project))
            {
                $assignee_type = $request['assignee_type'];
                $assignee_id = $request['assignee_id'];

                try
                {
                    switch ($assignee_type)
                    {
                        case 'user':
                            $assignee = entities\User::getB2DBTable()->selectById($assignee_id);
                            break;
                        case 'team':
                            $assignee = entities\Team::getB2DBTable()->selectById($assignee_id);
                            break;
                        default:
                            throw new \Exception('Invalid assignee');
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occurred when trying to assign user/team to this project')));
                }

                $assignee_role = new entities\Role($request['role_id']);
                $this->selected_project->addAssignee($assignee, $assignee_role);

                return $this->renderComponent('projects_assignees', array('project' => $this->selected_project));
            }
            else
            {
                $this->getResponse()->setHttpStatus(403);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__("You don't have access to save project settings")));
            }
        }

        /**
         * Configure project editions and components
         *
         * @param framework\Request $request The request object
         */
        public function runConfigureProjectEditionsAndComponents(framework\Request $request)
        {

        }

        /**
         * Configure project data types
         *
         * @param framework\Request $request The request object
         */
        public function runConfigureProjectOther(framework\Request $request)
        {

        }

        /**
         * Updates visible issue types
         *
         * @param framework\Request $request The request object
         */
        public function runConfigureProjectUpdateOther(framework\Request $request)
        {
            if ($this->getUser()->canEditProjectDetails($this->selected_project))
            {
                try
                {
                    $this->selected_project->setDownloadsEnabled((bool) $request['has_downloads']);
                    switch ($request['frontpage_summary'])
                    {
                        case 'issuelist':
                        case 'issuetypes':
                            $this->selected_project->setFrontpageSummaryType($request['frontpage_summary']);
                            $this->selected_project->save();
                            $this->selected_project->clearVisibleIssuetypes();
                            foreach ($request->getParameter('showissuetype', array()) as $issuetype_id)
                            {
                                $this->selected_project->addVisibleIssuetype($issuetype_id);
                            }
                            break;
                        case 'milestones':
                            $this->selected_project->setFrontpageSummaryType('milestones');
                            $this->selected_project->save();
                            $this->selected_project->clearVisibleMilestones();
                            foreach ($request->getParameter('showmilestone', array()) as $milestone_id)
                            {
                                $this->selected_project->addVisibleMilestone($milestone_id);
                            }
                            break;
                        case '':
                            $this->selected_project->setFrontpageSummaryType('');
                            $this->selected_project->save();
                            break;
                    }
                    return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Your changes have been saved'), 'message' => ''));
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured'), 'message' => $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(403);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__("You don't have access to save project settings")));
        }

        /**
         * Configure project builds
         *
         * @param framework\Request $request The request object
         */
        public function runConfigureProjectDevelopers(framework\Request $request)
        {

        }

        /**
         * Configure project leaders
         *
         * @param framework\Request $request The request object
         */
        public function runSetItemLead(framework\Request $request)
        {
            try
            {
                switch ($request['item_type'])
                {
                    case 'project':
                        $item = entities\Project::getB2DBTable()->selectById($request['project_id']);
                        break;
                    case 'edition':
                        $item = entities\Edition::getB2DBTable()->selectById($request['edition_id']);
                        break;
                    case 'component':
                        $item = entities\Component::getB2DBTable()->selectById($request['component_id']);
                        break;
                }
            }
            catch (\Exception $e)
            {

            }

            $this->forward403unless(isset($item) && $item instanceof entities\common\Identifiable);

            if ($request->hasParameter('value'))
            {
                $this->forward403unless(($request['item_type'] == 'project' && $this->getUser()->canEditProjectDetails($this->selected_project)) || ($request['item_type'] != 'project' && $this->getUser()->canManageProjectReleases($this->selected_project)));
                if ($request->hasParameter('identifiable_type'))
                {
                    if (in_array($request['identifiable_type'], array('team', 'user')) && $request['value'])
                    {
                        switch ($request['identifiable_type'])
                        {
                            case 'user':
                                $identified = entities\User::getB2DBTable()->selectById($request['value']);
                                break;
                            case 'team':
                                $identified = entities\Team::getB2DBTable()->selectById($request['value']);
                                break;
                        }
                        if ($identified instanceof entities\common\Identifiable)
                        {
                            if ($request['field'] == 'owned_by')
                                $item->setOwner($identified);
                            elseif ($request['field'] == 'qa_by')
                                $item->setQaResponsible($identified);
                            elseif ($request['field'] == 'lead_by')
                                $item->setLeader($identified);
                            $item->save();
                        }
                    }
                    else
                    {
                        if ($request['field'] == 'owned_by')
                            $item->clearOwner();
                        elseif ($request['field'] == 'qa_by')
                            $item->clearQaResponsible();
                        elseif ($request['field'] == 'lead_by')
                            $item->clearLeader();
                        $item->save();
                    }
                }
                if ($request['field'] == 'owned_by')
                    return $this->renderJSON(array('field' => (($item->hasOwner()) ? array('id' => $item->getOwner()->getID(), 'name' => (($item->getOwner() instanceof entities\User) ? $this->getComponentHTML('main/userdropdown', array('user' => $item->getOwner())) : $this->getComponentHTML('main/teamdropdown', array('team' => $item->getOwner())))) : array('id' => 0))));
                elseif ($request['field'] == 'lead_by')
                    return $this->renderJSON(array('field' => (($item->hasLeader()) ? array('id' => $item->getLeader()->getID(), 'name' => (($item->getLeader() instanceof entities\User) ? $this->getComponentHTML('main/userdropdown', array('user' => $item->getLeader())) : $this->getComponentHTML('main/teamdropdown', array('team' => $item->getLeader())))) : array('id' => 0))));
                elseif ($request['field'] == 'qa_by')
                    return $this->renderJSON(array('field' => (($item->hasQaResponsible()) ? array('id' => $item->getQaResponsible()->getID(), 'name' => (($item->getQaResponsible() instanceof entities\User) ? $this->getComponentHTML('main/userdropdown', array('user' => $item->getQaResponsible())) : $this->getComponentHTML('main/teamdropdown', array('team' => $item->getQaResponsible())))) : array('id' => 0))));
            }
        }

        /**
         * Configure project settings
         *
         * @param framework\Request $request The request object
         */
        public function runConfigureProjectSettings(framework\Request $request)
        {
            if ($request->isPost())
            {
                $this->forward403unless($this->getUser()->canEditProjectDetails($this->selected_project), framework\Context::getI18n()->__('You do not have access to update these settings'));

                if ($request->hasParameter('release_month') && $request->hasParameter('release_day') && $request->hasParameter('release_year'))
                {
                    $release_date = mktime(0, 0, 1, $request['release_month'], $request['release_day'], $request['release_year']);
                    $this->selected_project->setReleaseDate($release_date);
                }

                $old_key = $this->selected_project->getKey();

                if ($request->hasParameter('project_name'))
                {
                    if (trim($request['project_name']) == '')
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Please specify a valid project name')));
                    }
                    else
                    {
                        $this->selected_project->setName($request['project_name']);
                    }
                }


                $message = ($old_key != $this->selected_project->getKey()) ? framework\Context::getI18n()->__('%IMPORTANT: The project key has changed. Remember to replace the current url with the new project key', array('%IMPORTANT' => '<b>' . framework\Context::getI18n()->__('IMPORTANT') . '</b>')) : '';

                if ($request->hasParameter('project_key'))
                    $this->selected_project->setKey($request['project_key']);

                if ($request->hasParameter('use_prefix'))
                    $this->selected_project->setUsePrefix((bool) $request['use_prefix']);

                if ($request->hasParameter('use_prefix') && $this->selected_project->doesUsePrefix())
                {
                    if (!$this->selected_project->setPrefix($request['prefix']))
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__("Project prefixes may only contain letters and numbers")));
                    }
                }

                if ($request->hasParameter('client'))
                {
                    if ($request['client'] == 0)
                    {
                        $this->selected_project->setClient(null);
                    }
                    else
                    {
                        $this->selected_project->setClient(entities\Client::getB2DBTable()->selectById($request['client']));
                    }
                }

                if ($request->hasParameter('subproject_id'))
                {
                    if ($request['subproject_id'] == 0)
                    {
                        $this->selected_project->clearParent();
                    }
                    else
                    {
                        $this->selected_project->setParent(entities\Project::getB2DBTable()->selectById($request['subproject_id']));
                    }
                }

                if ($request->hasParameter('workflow_scheme'))
                {
                    try
                    {
                        $workflow_scheme = entities\WorkflowScheme::getB2DBTable()->selectById($request['workflow_scheme']);
                        $this->selected_project->setWorkflowScheme($workflow_scheme);
                    }
                    catch (\Exception $e)
                    {

                    }
                }

                if ($request->hasParameter('issuetype_scheme'))
                {
                    try
                    {
                        $issuetype_scheme = entities\IssuetypeScheme::getB2DBTable()->selectById($request['issuetype_scheme']);
                        $this->selected_project->setIssuetypeScheme($issuetype_scheme);
                    }
                    catch (\Exception $e)
                    {

                    }
                }

                if ($request->hasParameter('use_scrum'))
                    $this->selected_project->setUsesScrum((bool) $request['use_scrum']);

                if ($request->hasParameter('description'))
                    $this->selected_project->setDescription($request->getParameter('description', null, false));

                if ($request->hasParameter('homepage'))
                    $this->selected_project->setHomepage($request['homepage']);

                if ($request->hasParameter('doc_url'))
                    $this->selected_project->setDocumentationURL($request['doc_url']);

                if ($request->hasParameter('wiki_url'))
                    $this->selected_project->setWikiURL($request['wiki_url']);

                if ($request->hasParameter('released'))
                    $this->selected_project->setReleased((int) $request['released']);

                if ($request->hasParameter('locked'))
                    $this->selected_project->setLocked((bool) $request['locked']);

                if ($request->hasParameter('enable_builds'))
                    $this->selected_project->setBuildsEnabled((bool) $request['enable_builds']);

                if ($request->hasParameter('enable_editions'))
                    $this->selected_project->setEditionsEnabled((bool) $request['enable_editions']);

                if ($request->hasParameter('enable_components'))
                    $this->selected_project->setComponentsEnabled((bool) $request['enable_components']);

                if ($request->hasParameter('allow_changing_without_working'))
                    $this->selected_project->setChangeIssuesWithoutWorkingOnThem((bool) $request['allow_changing_without_working']);

                if ($request->hasParameter('allow_autoassignment'))
                    $this->selected_project->setAutoassign((bool) $request['allow_autoassignment']);

                $this->selected_project->save();
                return $this->renderJSON(array('message' => $this->getI18n()->__('Settings saved')));
            }
        }

        /**
         * Add an edition (AJAX call)
         *
         * @param framework\Request $request The request object
         */
        public function runAddEdition(framework\Request $request)
        {
            $i18n = framework\Context::getI18n();

            if ($this->getUser()->canEditProjectDetails($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    if (framework\Context::getUser()->canManageProjectReleases($this->selected_project))
                    {
                        if (($e_name = $request['e_name']) && trim($e_name) != '')
                        {
                            if (in_array($e_name, $this->selected_project->getEditions()))
                            {
                                throw new \Exception($i18n->__('This edition already exists for this project'));
                            }
                            $edition = $this->selected_project->addEdition($e_name);
                            return $this->renderJSON(array('html' => $this->getComponentHTML('editionbox', array('edition' => $edition, 'access_level' => framework\Settings::ACCESS_FULL))));
                        }
                        else
                        {
                            throw new \Exception($i18n->__('You need to specify a name for the new edition'));
                        }
                    }
                    else
                    {
                        throw new \Exception($i18n->__('You do not have access to this project'));
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array("error" => $i18n->__('The edition could not be added') . ", " . $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $i18n->__("You don't have access to add project editions")));
        }

        /**
         * Perform actions on a build (AJAX call)
         *
         * @param framework\Request $request The request object
         */
        public function runDeleteBuild(framework\Request $request)
        {
            $i18n = framework\Context::getI18n();

            try
            {
                if ($this->getUser()->canManageProjectReleases($this->selected_project))
                {
                    if ($b_id = $request['build_id'])
                    {
                        $build = entities\Build::getB2DBTable()->selectById($b_id);
                        if ($build->hasAccess())
                        {
                            $build->delete();
                            return $this->renderJSON(array('deleted' => true, 'message' => $i18n->__('The release was deleted')));
                        }
                        else
                        {
                            throw new \Exception($i18n->__('You do not have access to this release'));
                        }
                    }
                    else
                    {
                        throw new \Exception($i18n->__('You need to specify a release'));
                    }
                }
                else
                {
                    throw new \Exception($i18n->__("You don't have access to manage releases"));
                }
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array("error" => $e->getMessage()));
            }
        }

        /**
         * Add a build (AJAX call)
         *
         * @param framework\Request $request The request object
         */
        public function runProjectBuild(framework\Request $request)
        {
            $i18n = framework\Context::getI18n();

            if ($this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    if (framework\Context::getUser()->canManageProjectReleases($this->selected_project))
                    {
                        if (($b_name = $request['build_name']) && trim($b_name) != '')
                        {
                            $build = new entities\Build($request['build_id']);
                            $build->setName($b_name);
                            $build->setVersion($request->getParameter('ver_mj', 0), $request->getParameter('ver_mn', 0), $request->getParameter('ver_rev', 0));
                            $build->setReleased((bool) $request['isreleased']);
                            $build->setLocked((bool) $request['locked']);
                            if ($request['milestone'] && $milestone = entities\Milestone::getB2DBTable()->selectById($request['milestone']))
                            {
                                $build->setMilestone($milestone);
                            }
                            else
                            {
                                $build->clearMilestone();
                            }
                            if ($request['edition'] && $edition = entities\Edition::getB2DBTable()->selectById($request['edition']))
                            {
                                $build->setEdition($edition);
                            }
                            else
                            {
                                $build->clearEdition();
                            }
                            $release_date = null;
                            if ($request['has_release_date'])
                            {
                                $release_date = mktime($request['release_hour'], $request['release_minute'], 1, $request['release_month'], $request['release_day'], $request['release_year']);
                            }
                            $build->setReleaseDate($release_date);
                            switch ($request->getParameter('download', 'leave_file'))
                            {
                                case '0':
                                    $build->clearFile();
                                    $build->setFileURL('');
                                    break;
                                case 'upload_file':
                                    if ($build->hasFile())
                                    {
                                        $build->getFile()->delete();
                                        $build->clearFile();
                                    }
                                    $file = framework\Context::getRequest()->handleUpload('upload_file');
                                    $build->setFile($file);
                                    $build->setFileURL('');
                                    break;
                                case 'url':
                                    $build->clearFile();
                                    $build->setFileURL($request['file_url']);
                                    break;
                            }

                            if (!$build->getID())
                                $build->setProject($this->selected_project);

                            $build->save();
                        }
                        else
                        {
                            throw new \Exception($i18n->__('You need to specify a name for the release'));
                        }
                    }
                    else
                    {
                        throw new \Exception($i18n->__('You do not have access to this project'));
                    }
                }
                catch (\Exception $e)
                {
                    framework\Context::setMessage('build_error', $e->getMessage());
                }
                $this->forward(framework\Context::getRouting()->generate('project_release_center', array('project_key' => $this->selected_project->getKey())));
            }
            return $this->forward403($i18n->__("You don't have access to add releases"));
        }

        /**
         * Add a component (AJAX call)
         *
         * @param framework\Request $request The request object
         */
        public function runAddComponent(framework\Request $request)
        {
            $i18n = framework\Context::getI18n();

            if ($this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    if (($c_name = $request['c_name']) && trim($c_name) != '')
                    {
                        if (in_array($c_name, $this->selected_project->getComponents()))
                        {
                            throw new \Exception($i18n->__('This component already exists for this project'));
                        }
                        $component = $this->selected_project->addComponent($c_name);
                        return $this->renderJSON(array(/* 'title' => $i18n->__('The component has been added'), */'html' => $this->getComponentHTML('componentbox', array('component' => $component, 'access_level' => framework\Settings::ACCESS_FULL))));
                    }
                    else
                    {
                        throw new \Exception($i18n->__('You need to specify a name for the new component'));
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array("error" => $i18n->__('The component could not be added') . ", " . $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $i18n->__("You don't have access to add components")));
        }

        /**
         * Add or remove a component to/from an edition (AJAX call)
         *
         * @param framework\Request $request The request object
         */
        public function runEditEditionComponent(framework\Request $request)
        {
            $i18n = framework\Context::getI18n();

            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    $edition = entities\Edition::getB2DBTable()->selectById($request['edition_id']);
                    if ($request['mode'] == 'add')
                    {
                        $edition->addComponent($request['component_id']);
                    }
                    elseif ($request['mode'] == 'remove')
                    {
                        $edition->removeComponent($request['component_id']);
                    }
                    return $this->renderJSON('ok');
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array("error" => $i18n->__('The component could not be added to this edition') . ", " . $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $i18n->__("You don't have access to modify components")));
        }

        /**
         * Edit a component
         *
         * @param framework\Request $request The request object
         */
        public function runEditComponent(framework\Request $request)
        {
            $i18n = framework\Context::getI18n();

            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    $component = entities\Component::getB2DBTable()->selectById($request['component_id']);
                    if ($request['mode'] == 'update')
                    {
                        if (($c_name = $request['c_name']) && trim($c_name) != '')
                        {
                            if ($c_name == $component->getName())
                            {
                                return $this->renderJSON(array('newname' => $c_name));
                            }
                            if (in_array($c_name, $component->getProject()->getComponents()))
                            {
                                throw new \Exception($i18n->__('This component already exists for this project'));
                            }
                            $component->setName($c_name);
                            $component->save();
                            return $this->renderJSON(array('failed' => false, 'newname' => $component->getName()));
                        }
                        else
                        {
                            throw new \Exception($i18n->__('You need to specify a name for this component'));
                        }
                    }
                    elseif ($request['mode'] == 'delete')
                    {
                        $this->selected_project = $component->getProject();
                        $component->delete();
                        $count = $this->selected_project->countComponents();
                        return $this->renderJSON(array('deleted' => true, 'itemcount' => $count, 'message' => framework\Context::getI18n()->__('Component deleted')));
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array("error" => framework\Context::getI18n()->__('Could not edit this component') . ", " . $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $i18n->__("You don't have access to modify components")));
        }

        public function runDeleteEdition(framework\Request $request)
        {
            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    $edition = entities\Edition::getB2DBTable()->selectById($request['edition_id']);
                    $edition->delete();
                    $count = $this->selected_project->countEditions();
                    return $this->renderJSON(array('deleted' => true, 'itemcount' => $count, 'message' => framework\Context::getI18n()->__('Edition deleted')));
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array("error" => framework\Context::getI18n()->__('Could not delete this edition') . ", " . $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $this->getI18n()->__("You don't have access to modify edition")));
        }

        public function runConfigureProjectEdition(framework\Request $request)
        {
            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    if ($edition_id = $request['edition_id'])
                    {
                        $edition = entities\Edition::getB2DBTable()->selectById($edition_id);
                        if ($request->isPost())
                        {
                            if ($request->hasParameter('release_month') && $request->hasParameter('release_day') && $request->hasParameter('release_year'))
                            {
                                $release_date = mktime(0, 0, 1, $request['release_month'], $request['release_day'], $request['release_year']);
                                $edition->setReleaseDate($release_date);
                            }

                            if (($e_name = $request['edition_name']) && trim($e_name) != '')
                            {
                                if ($e_name != $edition->getName())
                                {
                                    if (in_array($e_name, $edition->getProject()->getEditions()))
                                    {
                                        throw new \Exception(framework\Context::getI18n()->__('This edition already exists for this project'));
                                    }
                                    $edition->setName($e_name);
                                }
                            }
                            else
                            {
                                throw new \Exception(framework\Context::getI18n()->__('You need to specify a name for this edition'));
                            }

                            $edition->setDescription($request->getParameter('description', null, false));
                            $edition->setDocumentationURL($request['doc_url']);
                            $edition->setReleased((int) $request['released']);
                            $edition->setLocked((bool) $request['locked']);
                            $edition->save();
                            return $this->renderJSON(array('edition_name' => $edition->getName(), 'message' => framework\Context::getI18n()->__('Edition details saved')));
                        }
                        else
                        {
                            switch ($request['mode'])
                            {
                                case 'releases':
                                case 'components':
                                    $this->selected_section = $request['mode'];
                                    break;
                                default:
                                    $this->selected_section = 'general';
                            }
                            $content = $this->getComponentHTML('project/projectedition', array('edition' => $edition, 'access_level' => $this->access_level, 'selected_section' => $this->selected_section));
                            return $this->renderJSON(array('content' => $content));
                        }
                    }
                    else
                    {
                        throw new \Exception(framework\Context::getI18n()->__('Invalid edition id'));
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $this->getI18n()->__("You don't have access to modify edition")));
        }

        public function runConfigureProject(framework\Request $request)
        {
            try
            {
                // Build list of valid targets for the subproject dropdown
                // The following items are banned from the list: current project, children of the current project
                // Any further tests and things get silly, so we will trap it when building breadcrumbs
                $valid_subproject_targets = entities\Project::getValidSubprojects($this->selected_project);
                $content = $this->getComponentHTML('project/projectconfig', array('valid_subproject_targets' => $valid_subproject_targets, 'project' => $this->selected_project, 'access_level' => $this->access_level, 'section' => 'hierarchy'));
                return $this->renderJSON(array('content' => $content));
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $e->getMessage()));
            }
        }

        public function runGetUpdatedProjectKey(framework\Request $request)
        {
            try
            {
                $this->selected_project = entities\Project::getB2DBTable()->selectById($request['project_id']);
            }
            catch (\Exception $e)
            {

            }

            if (!$this->selected_project instanceof entities\Project)
                return $this->return404(framework\Context::getI18n()->__("This project doesn't exist"));
            $this->selected_project->setName($request['project_name']);

            return $this->renderJSON(array('content' => $this->selected_project->getKey()));
        }

        public function runUnassignFromProject(framework\Request $request)
        {
            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    $assignee = ($request['assignee_type'] == 'user') ? new entities\User($request['assignee_id']) : new entities\Team($request['assignee_id']);
                    $this->selected_project->removeAssignee($assignee);
                    return $this->renderJSON(array('message' => framework\Context::getI18n()->__('The assignee has been removed')));
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('message' => $e->getMessage()));
                }
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array("error" => $this->getI18n()->__("You don't have access to perform this action")));
        }

        public function runProjectIcons(framework\Request $request)
        {
            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                if ($request->isPost())
                {
                    if ($request['clear_icons'])
                    {
                        $this->selected_project->clearSmallIcon();
                        $this->selected_project->clearLargeIcon();
                    }
                    else
                    {
                        switch ($request['small_icon_action'])
                        {
                            case 'upload_file':
                                $file = $request->handleUpload('small_icon');
                                $this->selected_project->setSmallIcon($file);
                                break;
                            case 'clear_file':
                                $this->selected_project->clearSmallIcon();
                                break;
                        }
                        switch ($request['large_icon_action'])
                        {
                            case 'upload_file':
                                $file = $request->handleUpload('large_icon');
                                $this->selected_project->setLargeIcon($file);
                                break;
                            case 'clear_file':
                                $this->selected_project->clearLargeIcon();
                                break;
                        }
                    }
                    $this->selected_project->save();
                }
                $route = framework\Context::getRouting()->generate('project_settings', array('project_key' => $this->selected_project->getKey()));
                if ($request->isAjaxCall())
                {
                    return $this->renderJSON(array('forward' => $route));
                }
                else
                {
                    $this->forward($route);
                }
            }
            return $this->forward403($this->getI18n()->__("You don't have access to perform this action"));
        }

        public function runProjectWorkflow(framework\Request $request)
        {
            if ($this->getUser()->canManageProject($this->selected_project) || $this->getUser()->canManageProjectReleases($this->selected_project))
            {
                try
                {
                    foreach ($this->selected_project->getIssuetypeScheme()->getIssuetypes() as $type)
                    {
                        $data = array();
                        foreach ($this->selected_project->getWorkflowScheme()->getWorkflowForIssuetype($type)->getSteps() as $step)
                        {
                            $data[] = array((string) $step->getID(), $request->getParameter('new_step_' . $type->getID() . '_' . $step->getID()));
                        }
                        $this->selected_project->convertIssueStepPerIssuetype($type, $data);
                    }

                    $this->selected_project->setWorkflowScheme(entities\WorkflowScheme::getB2DBTable()->selectById($request['workflow_id']));
                    $this->selected_project->save();

                    return $this->renderJSON(array('message' => framework\Context::geti18n()->__('Workflow scheme changed and issues updated')));
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHTTPStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::geti18n()->__('An internal error occured')));
                }
            }
            $this->getResponse()->setHTTPStatus(400);
            return $this->renderJSON(array('error' => framework\Context::geti18n()->__("You don't have access to perform this action")));
        }

        public function runProjectWorkflowTable(framework\Request $request)
        {
            $this->selected_project = entities\Project::getB2DBTable()->selectById($request['project_id']);
            if ($request->isPost())
            {
                try
                {
                    $workflow_scheme = entities\WorkflowScheme::getB2DBTable()->selectById($request['new_workflow']);
                    return $this->renderJSON(array('content' => $this->getComponentHTML('projectworkflow_table', array('project' => $this->selected_project, 'new_workflow' => $workflow_scheme))));
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHTTPStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::geti18n()->__('This workflow scheme is not valid')));
                }
            }
        }

        public function runAddRole(framework\Request $request)
        {
            if ($this->getUser()->canManageProject($this->selected_project))
            {
                if ($request['role_name'])
                {
                    $role = new entities\Role();
                    $role->setName($request['role_name']);
                    $role->setProject($this->selected_project);
                    $role->save();
                    return $this->renderJSON(array('content' => $this->getComponentHTML('configuration/role', array('role' => $role))));
                }
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('message' => $this->getI18n()->__('You must provide a role name')));
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('message' => $this->getI18n()->__('You do not have access to create new project roles')));
        }

    }
