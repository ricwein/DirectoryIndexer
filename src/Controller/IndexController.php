<?php

namespace App\Controller;

use App\Service\FileSystem;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Enum\Hash;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Path;
use ricwein\FileSystem\Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
            values: $storage->getFileType(true) ?? 'application/octet-stream'
        );
        $response
            ->setLastModified(DateTimeImmutable::createFromFormat('U', $storage->getTime()))
            ->setPublic()
            ->setEtag(
                md5(
                    $storage->getTime() . $storage->getFileHash(Hash::FILEPATH, 'md5')
                )
            );

        return $response;

    }

    private function viewFile(File $file): Response
    {
        $response = new BinaryFileResponse($file->getPath());

        $response->headers->set(
            key: 'Content-Type',
            values: $file->getType(true) ?? 'application/octet-stream'
        );
        $response
            ->setLastModified(DateTimeImmutable::createFromFormat('U', $file->getTime()))
            ->setPublic()
            ->setEtag(
                md5(
                    $file->getTime() . $file->getHash(Hash::FILEPATH, 'md5')
                )
            );

        return $response;
    }

    /**
     * @param Directory $directory
     * @return array<Path>
     */
    private function getPathTrail(Directory $directory): array
    {
        $dirs = array_filter(explode(DIRECTORY_SEPARATOR, $directory->getPath()->getRelativePath($this->rootPath)));

        $paths = [];
        $previousTrail = '';
        foreach ($dirs as $dir) {
            $paths[] = new Path($this->rootPath, $previousTrail, $dir);
            $previousTrail .= "/$dir";
        }

        return $paths;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function viewDirectory(Directory $directory): Response
    {
        $directoryIterator = $this->fileSystemService->iterate(directory: $directory);
        $trail = $this->getPathTrail($directory);
        return $this->render('pages/directory.html.twig', [
            'rootPath' => $this->rootPath,
            'files' => $directoryIterator,
            'directory' => $directory,
            'parentTrail' => $trail,
            'parentPath' => empty($trail) ? null : new Path($directory->getPath(), '../'),
        ]);
    }
}
