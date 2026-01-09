<?php

declare(strict_types=1);

namespace App\Service\Ticket;

use App\Domain\Entity\Ticket;
use App\Domain\ValueObject\Id\SessionTokenId;
use App\Model\TicketScore\TicketScore;
use App\Model\TicketScore\TicketScoreCollection;
use App\Repository\TicketRepository;
use Carbon\Carbon;
use Exception;
use Psr\Log\LoggerInterface;

class TicketUpdater
{
    private TicketRepository $ticketRepository;

    private LoggerInterface $logger;

    public function __construct(TicketRepository $ticketRepository, LoggerInterface $logger)
    {
        $this->ticketRepository = $ticketRepository;
        $this->logger = $logger;
    }

    public function updateReopenedTask(Ticket $ticket, SessionTokenId $sessionTokenId): void
    {
        $ticket->setSessionTokenId($sessionTokenId->asString());

        if (Ticket::STATUS_SUBMITTED === $ticket->getStatus()) {
            $ticket->setStatus(Ticketk::STATUS_SCORED);
        }

        $this->ticketRepository->persist($ticket);
        $this->ticketRepository->flush();
    }

    public function updateTaskWithValues(
        Ticket $ticket,
        ?string $note,
        ?bool $favorited,
        ?array $highlights,
        ?array $outputDeclarations
    ): void {
        try {
            $this->ticketRepository->beginTransaction();

            $ticket->setNote($note);

            if (null !== $favorited) {
                $ticket->setFavorited($favorited);
            }

            if (null !== $highlights) {
                $ticket->setHighlights($highlights);
            }

            if ($outputDeclarations) {
                $this->updateOutputDeclarations($outputDeclarations, $ticket);
            }

            $this->ticketRepository->flush();
            $this->ticketRepository->commit();
        } catch (Exception $exception) {
            $this->logger->error(
                sprintf(
                    'An error occurred while updating ticket with values, ticket id "%s". Error message: "%s".',
                    $ticket->getId()->asString(),
                    $exception->getMessage()
                )
            );

            $this->ticketRepository->rollback();

            throw $exception;
        }
    }

    private function updateOutputDeclarations(array $updatedOutputDeclarations, Task $task): void
    {
        $ticketScores = $ticket->getTicketScores();
        $newTicketScores = new TicketScoreCollection();

        /** @var TicketScore $ticketScore */
        foreach ($ticketScores as $ticketScore) {
            $outputDeclaration = $ticketScore->getOutputDeclaration();

            $key = sprintf(
                '%s-%s-%s',
                $ticketScore->getId(),
                $outputDeclaration->getId(),
                $outputDeclaration->getQtiIdentifier()
            );

            if (!array_key_exists($key, $updatedOutputDeclarations)) {
                $newTicketScores->add($ticketScore);
                continue;
            }

            $ticketScore->setValue($updatedOutputDeclarations[$key]);
            $newTaskScores->add($ticketScore);
        }

        $ticket->setTaskScores($newTicketScores);

        if ($ticket->areAllOutputDeclarationScored()) {
            $ticket->setStatus(Ticket::STATUS_SCORED);
        } else {
            $ticket->setStatus(Ticket::STATUS_UNSCORED);
        }

        $ticket
            ->setScoredAt(Carbon::now())
            ->setSubmittedAt(null)
        ;
    }
}
