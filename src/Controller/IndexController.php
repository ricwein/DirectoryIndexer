<?php

namespace App\Controller;

use App\Model\File;
use App\Services\PathHelper;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly PathHelper $pathHelper,
        private readonly string     $appIndexPath
    )
    {
    }

    #[Route(path: '/{path}', name: 'app_index', requirements: ['path' => '.*'], methods: 'GET')]
    public function view(string $path = ''): Response
    {
        $fileInfo = $this->pathHelper->loadPath($path);

        return match (true) {
            $fileInfo->isDir() => $this->showDirectory($fileInfo),
            $fileInfo->isFile() => $this->showFile($fileInfo),
            default => new Response('File not found.', 404)
        };
    }

    #[Route(path: '/{path}', name: 'app_file_info', requirements: ['path' => '.*'], methods: 'OPTIONS')]
    public function viewInfo(string $path = ''): Response
    {
        $fileInfo = $this->pathHelper->loadPath($path);
        return $this->render('pages/fileInfo.html.twig', [
            'file' => $fileInfo,
            'hash' => $fileInfo->isDir() ? [] : [
                'md5' => hash_file(algo: 'md5', filename: $fileInfo->getRealPath()),
                'sha1' => hash_file(algo: 'sha1', filename: $fileInfo->getRealPath()),
                'sha256' => hash_file(algo: 'sha256', filename: $fileInfo->getRealPath()),
                'sha512' => hash_file(algo: 'sha512', filename: $fileInfo->getRealPath()),
            ]
        ]);
    }

    private function showFile(SplFileInfo $file): Response
    {
        $response = new StreamedResponse(function () use ($file) {
            $outputStream = fopen('php://output', 'wb');
            $fileStream = fopen($file->getRealPath(), 'rb');
            stream_copy_to_stream($fileStream, $outputStream);
        });

        $response->headers->set('Content-Type', mime_content_type($file->getRealPath()));
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $file->getFilename(),
            $this->pathHelper->escapeFilename($file->getFilename()),
        ));

        return $response;
    }

    private function showDirectory(SplFileInfo $directory): Response
    {
        $files = (new Finder())
            ->in($directory->getRealPath())
            ->ignoreUnreadableDirs()
            ->depth('== 0')
            ->getIterator();

        return $this->render('pages/index.html.twig', [
            'files' => array_map(
                fn(\Symfony\Component\Finder\SplFileInfo $file): File => new File($this->appIndexPath, $file),
                iterator_to_array($files)
            ),
            'directoryPath' => '/' . ltrim(str_replace(trim($this->appIndexPath, '/'), '', $directory->getRealPath()), '/'),
        ]);
    }
}
