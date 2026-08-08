<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Notification;

use GbWeb\ContentFlow\Service\WorkspaceIntegrationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Fluid\View\TemplatePaths;

/**
 * Tells an editor they were handed a task - the one notification this
 * extension sends by email rather than through its own in-app timeline,
 * because an assignee is not necessarily looking at the board right now.
 *
 * Deliberately simple next to core's stage-change mail (which this extension
 * already surfaces via WorkspaceIntegrationService::buildNotificationRecipients()
 * for the transitions core itself handles): no PageTS override, no format
 * negotiation, one template. If that ever needs to grow, it grows from here.
 */
final class AssignmentNotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly WorkspaceIntegrationService $workspaceService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Skips silently (not an error) when the assignee is the acting editor
     * themselves, or has no usable email - matching kanban-workspaces'
     * AssignmentNotificationService, the prior art for this feature.
     */
    public function notifyAssignee(
        int $assigneeBeUserId,
        int $assignedByBeUserId,
        int $taskUid,
        string $taskTitle,
        string $subjectTitle,
        string $editUrl,
        string $note = '',
    ): void {
        if ($assigneeBeUserId === $assignedByBeUserId) {
            return;
        }

        $assigneeRecord = BackendUtility::getRecord('be_users', $assigneeBeUserId, 'uid,username,realName,email,lang,uc');
        if ($assigneeRecord === null) {
            return;
        }
        $recipient = $this->workspaceService->recipientFromBackendUser($assigneeRecord);
        if ($recipient === null) {
            return;
        }

        $assignerRecord = BackendUtility::getRecord('be_users', $assignedByBeUserId, 'uid,username,realName');
        $assignerName = $assignerRecord !== null
            ? (!empty($assignerRecord['realName']) ? (string)$assignerRecord['realName'] : (string)$assignerRecord['username'])
            : '';
        $recipientName = !empty($assigneeRecord['realName']) ? (string)$assigneeRecord['realName'] : (string)$assigneeRecord['username'];

        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths(['EXT:content_flow/Resources/Private/Templates/Email/']);
        $email = new FluidEmail($templatePaths);
        $email
            ->to(new Address($recipient['email'], $recipientName))
            ->subject(sprintf('Content Flow: "%s" was assigned to you', $taskTitle))
            ->format(FluidEmail::FORMAT_HTML)
            ->setTemplate('TaskAssigned')
            ->assignMultiple([
                'taskTitle' => $taskTitle,
                'subjectTitle' => $subjectTitle,
                'assignerName' => $assignerName,
                'note' => $note,
                'editUrl' => $editUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            // Silent to the editor, same as kanban-workspaces' equivalent: a
            // failed notification must not turn a successful assignment into a
            // visible error. The task is assigned either way.
            $this->logger->warning('assignment-notification-failed', [
                'taskUid' => $taskUid,
                'assigneeUid' => $assigneeBeUserId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
