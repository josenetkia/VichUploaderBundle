<?php

namespace Vich\UploaderBundle\Handler;


use Vich\UploaderBundle\Crypt\Encryption;
use Vich\UploaderBundle\Traits\Encrypt;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;
use Vich\UploaderBundle\Storage\StorageInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vich\UploaderBundle\Exception\NoFileFoundException;
use Vich\UploaderBundle\Util\Transliterator;
use Symfony\Component\HttpFoundation\File\File;

class DownloadHandler extends AbstractHandler
{
    use Encrypt;

    /**
     * @var Encryption
     */
    protected $encryption;

    /**
     * @param PropertyMappingFactory $factory
     * @param StorageInterface $storage
     * @param Encryption $encryption
     */
    public function __construct(
        PropertyMappingFactory $factory,
        StorageInterface $storage,
        Encryption $encryption
    )
    {
        parent::__construct($factory, $storage);
        $this->encryption = $encryption;
    }

    public function getFile($object, string $field, ?string $className = null):File
    {
        $mapping = $this->getMapping($object, $field, $className);
        if (!$this->isEncryptFile($mapping)) {
            return parent::downloadObject($object, $field, $className, $fileName, $forceDownload);
        }

        $path = $this->storage->resolvePath($object, $field, $className);

        if (null === $path) {
            throw new NoFileFoundException(sprintf('No file found in field "%s".', $field));
        }

        $fileName = !empty($fileName) ? $fileName : $mapping->getFileName($object);

        $newRealPath = tempnam(sys_get_temp_dir(), 'download_');
        file_put_contents($newRealPath, $this->encryption->decrypt(file_get_contents($path)));

        $file = new File($newRealPath, false);
        //$mimeType = $file ? $file->getMimeType() : null;

        return $file;

    }

    /**
     * Create a response object that will trigger the download of a file.
     *
     * @param object|array $object
     * @param string $field
     * @param string $className
     * @param string|bool $fileName True to return original file name
     *
     * @return StreamedResponse
     *
     * @throws \Vich\UploaderBundle\Exception\MappingNotFoundException
     * @throws NoFileFoundException
     * @throws \InvalidArgumentException
     */
    public function downloadObject($object, string $field, ?string $className = null, $fileName = null, bool $forceDownload = true): StreamedResponse
    {
        $mapping = $this->getMapping($object, $field, $className);
        if (!$this->isEncryptFile($mapping)) {
            return parent::downloadObject($object, $field, $className, $fileName, $forceDownload);
        }

        $stream = $this->storage->resolveStream($object, $field, $className);

        if (null === $stream) {
            throw new NoFileFoundException(sprintf('No file found in field "%s".', $field));
        }

        if (true === $fileName) {
            $fileName = $mapping->readProperty($object, 'originalName');
        }

        $fileName = !empty($fileName) ? $fileName : $mapping->getFileName($object);

        return $this->createResponse($stream, $fileName, $forceDownload);
    }

    /**
     * Create streamed response
     *
     * @param resource $stream
     * @param string $fileName
     * @param null|File $file
     *
     * @return StreamedResponse
     */
    protected function createResponse($stream, string $fileName, bool $forceDownload = true): StreamedResponse
    {
        $newRealPath = tempnam(sys_get_temp_dir(), 'download_');
        file_put_contents($newRealPath, $this->encryption->decrypt(stream_get_contents($stream)));

        $file = new File($newRealPath, false);
        $mimeType = $file ? $file->getMimeType() : null;
        $handle = fopen($newRealPath, 'rb');

        $response = new StreamedResponse(function () use ($handle) {
            stream_copy_to_stream($handle, fopen('php://output', 'wb'));
        });

        $disposition = $response->headers->makeDisposition(
            $forceDownload ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            Transliterator::transliterate($fileName)
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', $mimeType ?? 'application/octet-stream');

        return $response;
    }
}