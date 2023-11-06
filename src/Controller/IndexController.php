<?php

namespace App\Controller;

use App\Model\DTO\File as FileDTO;
use App\Model\DTO\Hashes;
use App\Service\FileSystem;
use Generator;
use Psr\Cache\InvalidArgumentException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Model\FileSize;
use ricwein\FileSystem\Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly FileSystem $fileSystemService,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    #[Route(path: '/{path}', name: 'app_index', requirements: ['path' => '.*'], methods: 'GET')]
    public function view(Request $request, string $path = ''): Response
    {
        $storage = new Storage\Disk($this->rootPath, $path);

        if ($request->query->get('preview') !== null) {
            $size = (int)$request->query->get('wh', 40);
            return $this->viewPreview($storage, $size);
        }

        // cleanup path in URL if required
        if (null !== $redirect = $this->cleanupRoutePath('app_index', $path, $storage)) {
            return $redirect;
        }

        $file = $this->fileSystemService->get($storage);
        return match (true) {
            $file instanceof Directory => $this->viewDirectory($file),
            $file instanceof File => $this->viewFile($file),
            default => new Response('File not found.', 404),
        };
    }

    private function cleanupRoutePath(string $route, string $path, Storage\BaseStorage&Storage\FileStorageInterface $storage): ?RedirectResponse
    {
        if (
            $path === '/'
            || str_contains($path, '/..')
            || str_contains($path, '../')
            || str_contains($path, '//')
            || ($storage->isFile() && str_ends_with($path, '/'))
            || (!empty(trim($path, '/')) && $storage->isDir() && !str_ends_with($path, '/'))
        ) {
            return $this->redirectToRoute($route, [
                'path' => (new File($storage))->getPath()->getRelativePath($this->rootPath),
            ], 301);
        }
        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function viewPreview(Storage\BaseStorage&Storage\FileStorageInterface $storage, int $size): Response
    {
        $preview = $this->fileSystemService->getPreview($storage, $size);
        $response = new StreamedResponse(function () use ($preview): void {
            $preview->stream();
        });

        $response->headers->set(
            key: 'Content-Type',
            values: $storage->getFileType() ?? 'application/octet-stream'
        );
        $response->headers->set(
            key: 'Content-Disposition',
            values: HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $storage->getPath()->getFilename(),
                $storage->getPath()->getEscapedFilename(),
            )
        );

        return $response;

    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route(path: '/{path}', name: 'app_file_info', requirements: ['path' => '.*'], methods: 'OPTIONS')]
    public function viewInfo(Request $request, string $path = ''): Response
    {
        $storage = new Storage\Disk($this->rootPath, $path);
        if (null === $file = $this->fileSystemService->get($storage)) {
            return new Response('File not found.', 404);
        }

        if (null !== $attribute = $request->query->get('attr')) {
            return new JsonResponse(
                data: match ($attribute) {
                    'size' => $this->fileSystemService->getSize($file, true),
                    'hashes' => $this->fileSystemService->getHashes($file, true),
                    default => throw new HttpException(400, "Invalid attribute '$attribute' requested."),
                },
                status: 200
            );
        }

        $hashes = $file->isDir() ? [] : array_map(
            static fn(string $algo) => $file->getHash(algo: $algo),
            ['md5' => 'md5', 'sha1' => 'sha1', 'sha256' => 'sha256', 'sha512' => 'sha512']
        );

        return $this->render('pages/fileInfo.html.twig', [
            'file' => $file,
            'hashes' => $hashes,
        ]);
    }

    private function viewFile(File $file): Response
    {
        $response = new StreamedResponse(function () use ($file): void {
            $file->stream();
        });

        $response->headers->set('Content-Type', $file->getType(true) ?? 'application/octet-stream');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->getPath()->getFilename(),
                $file->getPath()->getEscapedFilename(),
            )
        );

        return $response;
    }

    /**
     * @return Generator<array{file: FileDTO, size: null|FileSize, hashes: null|Hashes}>
     * @throws InvalidArgumentException
     */
    private function getDirectoryIterator(Directory $directory): Generator
    {
        foreach ($directory->list()->storages() as $storage) {
            if (null !== $data = $this->fileSystemService->getDTOs($storage, false)) {
                yield $data;
            }
        }
    }

    /**
     * @noinspection PhpParamsInspection
     */
    private function viewDirectory(Directory $directory): Response
    {
        $isAtRootLevel = rtrim($directory->getPath()->getRealPath(), '/') === rtrim($this->rootPath, '/');
        $parent = $isAtRootLevel ? null : (new Directory(clone $directory->storage()))->up();

        return $this->render('pages/index.html.twig', [
            'rootPath' => $this->rootPath,
            'files' => $this->getDirectoryIterator($directory),
            'directory' => $directory,
            'parentDir' => $parent,
        ]);
    }
}
