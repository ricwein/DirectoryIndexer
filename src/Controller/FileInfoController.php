<?php

namespace App\Controller;

use App\Service\FileSystem;
use Psr\Cache\InvalidArgumentException;
use ricwein\FileSystem\Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Annotation\Route;

class FileInfoController extends AbstractController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly FileSystem $fileSystemService,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    #[Route(path: '/{path}', name: 'app_file_info', requirements: ['path' => '.*'], methods: 'OPTIONS')]
    public function viewInfo(Request $request, string $path = ''): Response
    {
        $storage = new Storage\Disk($this->rootPath, $path);
        if (null === $file = $this->fileSystemService->get($storage)) {
            return new JsonResponse(['error' => 'File not found.'], 404);
        }

        if (null === $attribute = $request->query->get('attr')) {
            return new JsonResponse(['error' => 'Invalid request - missing attribute.'], 400);
        }

        return match ($attribute) {
            'size' => new JsonResponse($this->fileSystemService->getSize($file, true), 200),
            'hashes' => new JsonResponse($this->fileSystemService->getHashes($file, true), 200),
            default => new JsonResponse(['error' => 'Invalid request - unsupported attribute.'], 400),
        };
    }

}
