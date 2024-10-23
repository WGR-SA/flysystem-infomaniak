<?php

namespace Wgr\Flysystem\Infomaniak\Adapter;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use GuzzleHttp\Psr7\Stream;
use OpenStack\OpenStack as Client;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use OpenStack\ObjectStore\v1\Api;
use Wgr\Flysystem\Infomaniak\Mime;

class OpenStack implements FilesystemAdapter
{
  private Client $client;
  private string $containerName;
  private PathPrefixer $prefixer;
  private ?object $service = null;
  private ?object $container = null;
  private Api $api;

  public function __construct(Client $client, string $container, string $prefix = '')
  {
    $this->api = new Api();
    $this->client = $client;
    $this->containerName = $container;
    $this->prefixer = new PathPrefixer($prefix);
  }

  private function getService()
  {
    if (!$this->service) {
      $this->service = $this->client->objectStoreV1();
    }
    return $this->service;
  }

  private function getContainer()
  {
    if (!$this->container) {
      $this->container = $this->getService()->getContainer($this->containerName);
    }
    return $this->container;
  }

  private function getOptionsFor(string $fctName = 'postObject'): array
  {
    $array = call_user_func([$this->api, $fctName]);
    if (empty($array['params'])) {
      return [];
    }
    return array_keys($array['params']);
  }

  public function fileExists(string $path): bool
  {
    $location = $this->prefixer->prefixPath($path);

    try {
      return $this->getContainer()->objectExists($location);
    } catch (\Exception $e) {
      throw UnableToCheckExistence::forLocation($path, $e);
    }
  }

  public function directoryExists(string $path): bool
  {
    $location = $this->prefixer->prefixPath($path);

    try {
      $objects = $this->getContainer()->listObjects([
        'prefix' => $location,
        'limit' => 1,
      ]);

      return iterator_count($objects) > 0;
    } catch (\Exception $e) {
      throw UnableToCheckExistence::forLocation($path, $e);
    }
  }

  public function write(string $path, string $contents, Config $config): void
  {
    $location = $this->prefixer->prefixPath($path);

    try {
      $obj = array_merge($this->mergeConfig('putObject', $config), [
        'name' => $location,
        'content' => $contents
      ]);

      $this->getContainer()->createObject($obj);
    } catch (\Exception $e) {
      throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  public function writeStream(string $path, $contents, Config $config): void
  {
    $location = $this->prefixer->prefixPath($path);

    try {
      $obj = array_merge($this->mergeConfig('putObject', $config), [
        'name' => $location,
        'stream' => new Stream($contents)
      ]);

      $this->getContainer()->createObject($obj);
    } catch (\Exception $e) {
      throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  public function read(string $path): string
  {
    try {
      $object = $this->readStream($path);
      return stream_get_contents($object);
    } catch (\Exception $e) {
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    }
  }

  public function readStream(string $path)
  {
    try {
      $location = $this->prefixer->prefixPath($path);
      $object = $this->getContainer()->getObject($location);
      return $object->download()->detach();
    } catch (\Exception $e) {
      throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
    }
  }

  public function delete(string $path): void
  {
    try {
      $location = $this->prefixer->prefixPath($path);
      $this->getContainer()->getObject($location)->delete();
    } catch (\Exception $e) {
      throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  public function deleteDirectory(string $path): void
  {
    $location = $this->prefixer->prefixPath($path);

    try {
      $objects = $this->getContainer()->listObjects([
        'prefix' => $location
      ]);

      foreach ($objects as $object) {
        $this->getContainer()->getObject($object->name)->delete();
      }
    } catch (\Exception $e) {
      throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
    }
  }

  public function createDirectory(string $path, Config $config): void
  {
    try {
      $obj = array_merge($this->mergeConfig('postObject', $config), [
        'name' => $this->prefixer->prefixPath($path),
        'contentType' => 'application/directory'
      ]);

      $this->getContainer()->createObject($obj);
    } catch (\Exception $e) {
      throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
    }
  }

  public function setVisibility(string $path, string $visibility): void
  {
    throw UnableToSetVisibility::atLocation($path, 'OpenStack does not support visibility settings');
  }

  public function visibility(string $path): FileAttributes
  {
    return $this->fileAttributes($path);
  }

  public function mimeType(string $path): FileAttributes
  {
    return $this->fileAttributes($path);
  }

  public function lastModified(string $path): FileAttributes
  {
    return $this->fileAttributes($path);
  }

  public function fileSize(string $path): FileAttributes
  {
    return $this->fileAttributes($path);
  }

  public function listContents(string $path, bool $deep): iterable
  {
    $location = $this->prefixer->prefixPath($path);

    try {
      $objectList = $this->getContainer()->listObjects([
        'prefix' => $location,
      ]);

      foreach ($objectList as $object) {
        yield $this->normalizeObject($object);
      }
    } catch (\Exception $e) {
      // Handle the exception or let it propagate
      throw new \RuntimeException("Unable to list contents at {$path}", 0, $e);
    }
  }

  public function move(string $source, string $destination, Config $config): void
  {
    try {
      // OpenStack doesn't have a native move operation
      // So we'll implement it as copy + delete
      $this->copy($source, $destination, $config);
      $this->delete($source);
    } catch (\Exception $e) {
      throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
    }
  }

  public function copy(string $source, string $destination, Config $config): void
  {
    $sourceLocation = $this->prefixer->prefixPath($source);
    $destinationLocation = $this->prefixer->prefixPath($destination);

    try {
      // Read the source file
      $sourceObject = $this->getContainer()->getObject($sourceLocation);

      // Create new object with the same content
      $obj = array_merge($this->mergeConfig('putObject', $config), [
        'name' => $destinationLocation,
        'content' => $sourceObject->download()->getContents()
      ]);

      $this->getContainer()->createObject($obj);
    } catch (\Exception $e) {
      throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
    }
  }

  private function normalizeObject(StorageObject $object): FileAttributes|DirectoryAttributes
  {
    $path = $this->prefixer->stripPrefix($object->name);

    if ($object->contentType === 'application/directory') {
      return new DirectoryAttributes(trim($path, '/'));
    }

    $mimetype = $object->contentType ?: Mime::getMime($path);
    $timestamp = $object->lastModified ? $object->lastModified->getTimestamp() : null;

    return new FileAttributes(
      trim($path, '/'),
      $object->contentLength ?? null,
      null, // visibility
      $timestamp,
      $mimetype
    );
  }

  private function fileAttributes(string $path): FileAttributes
  {
    try {
      $location = $this->prefixer->prefixPath($path);
      $object = $this->getContainer()->getObject($location);
      return $this->normalizeObject($object);
    } catch (\Exception $e) {
      throw UnableToRetrieveMetadata::create($path, 'metadata', $e->getMessage(), $e);
    }
  }

  private function mergeConfig(string $method = 'postObject', Config $config = null, array $obj = []): array
  {
    if (!$config) {
      return [];
    }

    foreach ($this->getOptionsFor($method) as $opt) {
      if ($config->get($opt, false)) {
        $obj[$opt] = $config->get($opt);
      }
    }

    return $obj;
  }
}