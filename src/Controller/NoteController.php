<?php

namespace App\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class NoteController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    // Creates the schema with two notes: "alice" and "deleted-note" (soft-deleted)
    #[Route('/setup')]
    public function setup(): JsonResponse
    {
        $tool = new SchemaTool($this->em);
        $classes = [$this->em->getClassMetadata(Note::class)];
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
        $this->em->persist(new Note('alice'));
        $this->em->persist(new Note('deleted-note', deleted: true));
        $this->em->flush();

        return new JsonResponse('ok');
    }

    // 1. SQL filters: an admin page disables the soft-delete filter (enabled by default)
    #[Route('/notes')]
    public function notes(Request $request): JsonResponse
    {
        if ($request->query->has('admin')) {
            $this->em->getFilters()->disable('soft_delete');
        }

        return new JsonResponse([
            'enabled_filters' => array_keys($this->em->getFilters()->getEnabledFilters()),
            'notes' => array_map(fn (Note $n) => $n->owner, $this->em->getRepository(Note::class)->findAll()),
        ]);
    }

    // 2. A request fails while a transaction is open
    #[Route('/transaction/fail')]
    public function failInTransaction(): JsonResponse
    {
        $this->em->getConnection()->beginTransaction();
        $this->em->persist(new Note('failed-order'));
        $this->em->flush();

        throw new \RuntimeException('Payment gateway timeout');
    }

    // The next request writes without any explicit transaction
    #[Route('/transaction/write')]
    public function write(): JsonResponse
    {
        $this->em->persist(new Note('bob-order'));
        $this->em->flush();

        return new JsonResponse(['transaction_nesting_level' => $this->em->getConnection()->getTransactionNestingLevel()]);
    }

    // What any other process sees, through an independent connection
    #[Route('/transaction/committed')]
    public function committed(): JsonResponse
    {
        $pdo = new \PDO('sqlite:/tmp/app.db');

        return new JsonResponse(['committed_notes' => $pdo->query('SELECT owner FROM note ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)]);
    }
}
