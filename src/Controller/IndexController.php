<?php

namespace App\Controller;

use App\Model\CachedFileSystem\CacheableDirectory;
use App\Model\FileInfo;
use App\Service\CacheableFileSystem;
use Generator;
use Psr\Cache\InvalidArgumentException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    private const HASHES = [
        'md5' => 'md5',
        'sha1' => 'sha1',
        'sha256' => 'sha256',
        'sha512' => 'sha512'
    ];

    public function __construct(
        private readonly string $appIndexPath,
        private readonly CacheableFileSystem $cachedFileSystem,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    #[Route(path: '/{path}', name: 'app_index', requirements: ['path' => '.*'], methods: 'GET')]
    public function view(Request $request, string $path = ''): Response
    {
        $storage = new Storage\Disk($this->appIndexPath, $path);

        if ($request->query->get('preview')!==null) {
            return $this->viewPreview($storage);
        }

        // cleanup path in URL if required
        if (
            $path === '/'
            || str_contains($path, '/..')
            || str_contains($path, '../')
            || str_contains($path, '//')
        ) {
            return $this->redirectToRoute('app_index', [
                'path' => (new File($storage))->getPath()->getRelativePathToSafePath(),
            ]);
        }

        $file = $this->cachedFileSystem->get($storage);

        return match (true) {
            $file instanceof Directory => $this->viewDirectory($file),
            $file instanceof File => $this->viewFile($file),
            default => new Response('File not found.', 404),
        };
    }

    private function viewPreview(Storage\BaseStorage $storage): Response
    {
        // TODO return file preview
        return new Response("TODO $storage");
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route(path: '/{path}', name: 'app_file_info', requirements: ['path' => '.*'], methods: 'OPTIONS')]
    public function viewInfo(Request $request, string $path = ''): Response
    {
        $storage = new Storage\Disk($this->appIndexPath, $path);
        if (null === $file = $this->cachedFileSystem->get($storage)) {
            new Response('File not found.', 404);
        }

        if (null !== $attribute = $request->query->get('attr')) {
            return new JsonResponse(
                data: [$attribute => match ($attribute) {
                    'size' => $file->getSize(),
                    'hashes' => array_map(static fn(string $algo) => $file->getHash(algo: $algo), self::HASHES),
                    default => throw new HttpException(400, "Invalid attribute '$attribute' requested."),
                }],
                status: 200
            );
        }


        $hashes = $file?->isDir() ? [] : array_map(
            static fn(string $algo) => $file->getHash(algo: $algo),
            self::HASHES
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
     * @return Generator<FileInfo>
     * @throws InvalidArgumentException
     */
    private function getDirectoryIterator(Directory $directory): Generator
    {
        foreach ($directory->list()->storages() as $storage) {
            if (null !== $file = $this->cachedFileSystem->get($storage)) {
                yield new FileInfo($file);
            }
        }
    }

    /**
     * @noinspection PhpParamsInspection
     * @throws InvalidArgumentException
     */
    private function viewDirectory(CacheableDirectory $directory): Response
    {
        $isAtRootLevel = rtrim($directory->getPath()->getRealPath(), '/') === rtrim($this->appIndexPath, '/');
        $parent = $isAtRootLevel ? null : (new Directory(clone $directory->storage()))->up();

        return $this->render('pages/index.html.twig', [
            'rootPath' => $this->appIndexPath,
            'files' => $this->getDirectoryIterator($directory),
            'directory' => $directory,
            'parentDir' => $parent,
        ]);
    }
}
