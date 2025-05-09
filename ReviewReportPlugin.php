<?php

/**
 * @file ReviewReportPlugin.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewReportPlugin
 *
 * @ingroup plugins_reports_review
 *
 * @see ReviewReportDAO
 *
 * @brief Review report plugin
 */

namespace APP\plugins\reports\reviewReport;

use APP\core\Application;
use APP\facades\Repo;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\plugins\ReportPlugin;
use PKP\reviewForm\ReviewFormElement;
use PKP\reviewForm\ReviewFormElementDAO;
use PKP\reviewForm\ReviewFormResponseDAO;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewer\recommendation\RecommendationOption;
use PKP\workflow\WorkflowStageDAO;

class ReviewReportPlugin extends ReportPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance()) {
            return $success;
        }

        $reviewReportDAO = new ReviewReportDAO();
        DAORegistry::registerDAO('ReviewReportDAO', $reviewReportDAO);
        $this->addLocaleData();
        return $success;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName()
    {
        return 'ReviewReportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.reports.reviews.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.reports.reviews.description');
    }

    /**
     * @copydoc ReportPlugin::display()
     */
    public function display($args, $request)
    {
        $context = $request->getContext();

        header('content-type: text/comma-separated-values');
        header('content-disposition: attachment; filename=reviews-' . date('Ymd') . '.csv');

        $reviewReportDao = DAORegistry::getDAO('ReviewReportDAO'); /** @var ReviewReportDAO $reviewReportDao */
        [$commentsIterator, $reviewsIterator, $interestsArray] = $reviewReportDao->getReviewReport($context->getId());

        $comments = [];
        foreach ($commentsIterator as $row) {
            if (isset($comments[$row->submission_id][$row->author_id])) {
                $comments[$row->submission_id][$row->author_id] .= '; ' . $row->comments;
            } else {
                $comments[$row->submission_id][$row->author_id] = $row->comments;
            }
        }

        $recommendations = Repo::reviewerRecommendation()->getRecommendationOptions($context, RecommendationOption::ALL);

        $considerations = [
            ReviewAssignment::REVIEW_ASSIGNMENT_NEW => 'plugins.reports.reviews.considered.new',
            ReviewAssignment::REVIEW_ASSIGNMENT_CONSIDERED => 'plugins.reports.reviews.considered.considered',
            ReviewAssignment::REVIEW_ASSIGNMENT_UNCONSIDERED => 'plugins.reports.reviews.considered.unconsidered',
            ReviewAssignment::REVIEW_ASSIGNMENT_RECONSIDERED => 'plugins.reports.reviews.considered.reconsidered',
        ];

        $columns = [
            'stage_id' => __('workflow.stage'),
            'round' => __('plugins.reports.reviews.round'),
            'submission' => __('plugins.reports.reviews.submissionTitle'),
            'submission_id' => __('plugins.reports.reviews.submissionId'),
            'reviewer' => __('plugins.reports.reviews.reviewer'),
            'user_given' => __('user.givenName'),
            'user_family' => __('user.familyName'),
            'orcid' => __('user.orcid'),
            'country' => __('common.country'),
            'affiliation' => __('user.affiliation'),
            'email' => __('user.email'),
            'interests' => __('user.interests'),
            'date_assigned' => __('plugins.reports.reviews.dateAssigned'),
            'date_notified' => __('plugins.reports.reviews.dateNotified'),
            'date_confirmed' => __('plugins.reports.reviews.dateConfirmed'),
            'date_completed' => __('plugins.reports.reviews.dateCompleted'),
            'date_acknowledged' => __('plugins.reports.reviews.dateAcknowledged'),
            'considered' => __('plugins.reports.reviews.considered'),
            'date_reminded' => __('plugins.reports.reviews.dateReminded'),
            'date_response_due' => __('reviewer.submission.responseDueDate'),
            'overdue_response' => __('plugins.reports.reviews.responseOverdue'),
            'date_due' => __('reviewer.submission.reviewDueDate'),
            'overdue' => __('plugins.reports.reviews.reviewOverdue'),
            'declined' => __('submissions.declined'),
            'cancelled' => __('common.cancelled'),
            'reviewer_recommendation_id' => __('plugins.reports.reviews.recommendation'),
            'comments' => __('plugins.reports.reviews.comments')
        ];

        $fp = fopen('php://output', 'wt');
        //Add BOM (byte order mark) to fix UTF-8 in Excel
        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fp, array_values($columns));

        /** @var ReviewFormResponseDAO */
        $reviewFormResponseDao = DAORegistry::getDAO('ReviewFormResponseDAO');
        /** @var ReviewFormElementDAO */
        $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO');

        foreach ($reviewsIterator as $row) {
            if (substr($row->date_response_due, 11) === '00:00:00') {
                $row->date_response_due = substr($row->date_response_due, 0, 11) . '23:59:59';
            }
            if (substr($row->date_due, 11) === '00:00:00') {
                $row->date_due = substr($row->date_due, 0, 11) . '23:59:59';
            }
            [$overdueResponseDays, $overdueDays] = $this->getOverdueDays($row);
            $row->overdue_response = $overdueResponseDays;
            $row->overdue = $overdueDays;

            foreach ($columns as $index => $junk) {
                switch ($index) {
                    case 'stage_id':
                        $columns[$index] = __(WorkflowStageDAO::getTranslationKeyFromId($row->$index));
                        break;
                    case 'declined':
                    case 'cancelled':
                        $columns[$index] = __($row->$index ? 'common.yes' : 'common.no');
                        break;
                    case 'considered':
                        $columns[$index] = isset($considerations[$row->$index]) ? __($considerations[$row->$index]) : '';
                        break;
                    case 'reviewer_recommendation_id':
                        $columns[$index] = isset($row->$index) ? $recommendations[$row->$index] : '';
                        break;
                    case 'comments':
                        $reviewAssignment = Repo::reviewAssignment()->get($row->review_id, $row->submission_id);
                        $body = '';

                        if ($reviewAssignment->getDateCompleted() != null && ($reviewFormId = $reviewAssignment->getReviewFormId())) {
                            $reviewId = $reviewAssignment->getId();
                            $reviewFormElements = $reviewFormElementDao->getByReviewFormId($reviewFormId);
                            while ($reviewFormElement = $reviewFormElements->next()) { /** @var ReviewFormElement $reviewFormElement */
                                if (!$reviewFormElement->getIncluded()) {
                                    continue;
                                }
                                $body .= PKPString::stripUnsafeHtml($reviewFormElement->getLocalizedQuestion());
                                $reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewId, $reviewFormElement->getId());
                                if ($reviewFormResponse) {
                                    $possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
                                    if (in_array($reviewFormElement->getElementType(), [$reviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES, $reviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS])) {
                                        ksort($possibleResponses);
                                        $possibleResponses = array_values($possibleResponses);
                                    }
                                    if (in_array($reviewFormElement->getElementType(), $reviewFormElement->getMultipleResponsesElementTypes())) {
                                        if ($reviewFormElement->getElementType() == $reviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
                                            $body .= '<ul>';
                                            foreach ($reviewFormResponse->getValue() as $value) {
                                                $body .= '<li>' . PKPString::stripUnsafeHtml($possibleResponses[$value]) . '</li>';
                                            }
                                            $body .= '</ul>';
                                        } else {
                                            $body .= '<blockquote>' . PKPString::stripUnsafeHtml($possibleResponses[$reviewFormResponse->getValue()]) . '</blockquote>';
                                        }
                                        $body .= '<br>';
                                    } else {
                                        $body .= '<blockquote>' . nl2br(htmlspecialchars($reviewFormResponse->getValue())) . '</blockquote>';
                                    }
                                }
                            }
                        }

                        $columns[$index] = $comments[$row->submission_id][$row->reviewer_id] ?? $body;
                        break;
                    case 'interests':
                        $columns[$index] = $interestsArray[$row->reviewer_id] ?? '';
                        break;
                    default:
                        $columns[$index] = $row->$index;
                }
            }
            fputcsv($fp, $columns);
        }
        fclose($fp);
    }

    public function getOverdueDays($row)
    {
        $responseDueTime = strtotime($row->date_response_due);
        $reviewDueTime = strtotime($row->date_due);
        $overdueResponseDays = $overdueDays = '';
        if (!$row->date_confirmed) { // no response
            if ($responseDueTime < time()) { // response overdue
                $datediff = time() - $responseDueTime;
                $overdueResponseDays = round($datediff / (60 * 60 * 24));
            } elseif ($reviewDueTime < time()) { // review overdue but not response
                $datediff = time() - $reviewDueTime;
                $overdueDays = round($datediff / (60 * 60 * 24));
            }
        } elseif (!$row->date_completed) { // response given, but not completed
            if ($reviewDueTime < time()) { // review due
                $datediff = time() - $reviewDueTime;
                $overdueDays = round($datediff / (60 * 60 * 24));
            }
        }
        return [$overdueResponseDays, $overdueDays];
    }
}
