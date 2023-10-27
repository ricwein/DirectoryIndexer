<?php

namespace App\Controller;

use App\Model\FileInfo;
use App\Services\RootPathHelper;
use Generator;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly RootPathHelper $pathHelper,
        private readonly string $appIndexPath
    ) {}

    #[Route(path: '/{path}', name: 'app_index', requirements: ['path' => '.*'], methods: 'GET')]
    public function view(string $path = ''): Response
    {
        if (str_contains($path, '/..') || str_contains($path, '../') || str_contains($path, '//')) {
            return $this->redirectToRoute('app_index', [
                'path' => $this->pathHelper->normalizeRelativePath($path),
            ]);
        }

        $storage = new Storage\Disk($this->appIndexPath, $path);

        return match (true) {
            $storage->isDir() => $this->showDirectory(new Directory($storage)),
            $storage->isFile() => $this->showFile(new File($storage)),
            default => new Response('File not found.', 404)
        };
    }

    #[Route(path: '/{path}', name: 'app_file_info', requirements: ['path' => '.*'], methods: 'OPTIONS')]
    public function viewInfo(string $path = ''): Response
    {
        $fileInfo = $this->pathHelper->loadPath($path);
        $file = new File(new Storage\Disk($fileInfo), Constraint::LOOSE);
        return $this->render('pages/fileInfo.html.twig', [
            'file' => $file,
            'hashes' => $file->isDir()
                ? []
                : array_map(
                    static fn(string $algo) => $file->getHash(algo: $algo),
                    ['md5' => 'md5', 'sha1' => 'sha1', 'sha256' => 'sha256', 'sha512' => 'sha512']
                )
        ]);
    }

    private function showFile(File $file): Response
    {
        $response = new StreamedResponse(function () use ($file) {
            $file->stream();
        });

        $response->headers->set('Content-Type', $file->getType(true));
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->getPath()->getFilename(),
                $this->pathHelper->escapeFilename($file->getPath()->getFilename()),
            )
        );

        return $response;
    }

    /** @return Generator<FileInfo> */
    private static function getDirectoryIterator(Directory $directory): Generator
    {
        foreach ($directory->list()->all(constraints: Constraint::LOOSE) as $file) {
            yield new FileInfo($file);
        }
    }

    /** @noinspection PhpParamsInspection */
    private function showDirectory(Directory $directory): Response
    {
        $isAtRootLevel = rtrim($directory->getPath()->getRealPath(), '/') === rtrim($this->appIndexPath, '/');
        return $this->render('pages/index.html.twig', [
            'rootPath' => $this->appIndexPath,
            'files' => self::getDirectoryIterator($directory),
            'directory' => $directory,
            'parentDir' => $isAtRootLevel ? null : (new Directory(clone $directory->storage()))->up(),
        ]);
    }
}
