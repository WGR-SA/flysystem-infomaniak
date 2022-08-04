<?php

namespace Wgr\Flysystem\Infomaniak\Adapter;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\StreamedTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use GuzzleHttp\Psr7\Stream;
use OpenStack\OpenStack as Client;
use OpenStack\ObjectStore\v1\Models\StorageObject;

class OpenStack extends AbstractAdapter
{
  use StreamedTrait;
  use StreamedCopyTrait;

  protected $client;

  protected $containerName;

  protected $options = [];

  protected $service;

  protected $container;

  /**
   * @var array
   */
  protected static $resultMap = [
      'content-length' => 'size',
      'content-type' => 'mimetype',
  ];

  public function __construct(Client $client, $container, $prefix = '')
  {
    $this->client = $client;
    $this->containerName = $container;
    $this->setPathPrefix($prefix);
  }

  protected function getService()
  {
    if(!$this->service) $this->service = $this->client->objectStoreV1();
    return $this->service;
  }

  protected function getContainer()
  {
    if(!$this->container) $this->container = $this->getService()->getContainer($this->containerName);
    return $this->container;
  }

  /**
  * {@inheritdoc}
  */
  public function applyPathPrefix($path)
  {
    return ltrim(parent::applyPathPrefix($path), '/');
  }

  /**
  * {@inheritdoc}
  */
  public function setPathPrefix($prefix)
  {
    $prefix = ltrim($prefix, '/');

    return parent::setPathPrefix($prefix);
  }

  /**
  * Check whether a file is present.
  *
  * @param string $path
  *
  * @return bool
  */
  public function has($path)
  {
    $location = $this->applyPathPrefix($path);
    return $this->getContainer()->objectExists($location);
  }

  /**
  * @inheritdoc
  */
  public function write($path, $contents, Config $config)
  {
    $obj = [
      'name' => $this->applyPathPrefix($path),
      'content' => $contents
    ];
    if($config->get('mimetype')) $obj['contentType'] = $config->get('mimetype');
    return $this->getContainer()->createObject($obj);
  }

  /**
  * Write a new file using a stream.
  *
  * @param string   $path
  * @param resource $resource
  * @param Config   $config Config object
  *
  * @return array|false false on failure file meta data on success
  */
  public function writeStream($path, $resource, Config $config)
  {
    $obj = [
      'name' => $this->applyPathPrefix($path),
      'stream' => new Stream($resource)//new Stream($resource)
    ];
    if($config->get('mimetype')) $obj['contentType'] = $config->get('mimetype');
    return $this->getContainer()->createObject($obj);
  }

  /**
  * @inheritdoc
  */
  public function update($path, $contents, Config $config)
  {
    $this->delete($path);
    $this->write($path, $contents, $config);
  }

  public function updateStream($path, $resource, Config $config)
  {
    $this->delete($path);
    $this->writeStream($path, $resource, $config);
  }

  /**
  * @inheritdoc
  */
  public function read($path)
  {
    return $this->readStream($path)->getContents();
  }

  public function readStream($path)
  {
    $location = $this->applyPathPrefix($path);
    return $this->getContainer()->getObject($location);//->download();
  }

  /**
  * @inheritdoc
  */
  public function rename($path, $newpath)
  {
    return false;
  }

  /**
  * @inheritdoc
  */
  public function delete($path)
  {
    $location = $this->applyPathPrefix($path);
    $this->getContainer()->getObject($location)->delete();
  }

  /**
  * @inheritdoc
  */
  public function listContents($directory = '', $recursive = false)
  {
    $response = [];
    $marker = null;
    $location = $this->applyPathPrefix($directory);

    while(true)
    {
      $objectList = $this->getContainer()->listObjects([
        'prefix' => $location,
        'marker' => $marker,
        //'limit' => 100
      ]);
      $response = array_merge($response, iterator_to_array($objectList));

      /* No time to check how to get all record with marker implementation
      if (count($response) === 0) break;
      $marker = end($response)->name;
      */

      break;
    }

    return Util::emulateDirectories(array_map([$this, 'normalizeObject'], $response));
  }

  public function getObjectArray($path)
  {
    $location = $this->applyPathPrefix($path);
    $obj = $this->getContainer()->getObject($location);
    return $this->normalizeObject($obj);
  }

  /**
   * Normalise a WebDAV repsonse object.
   *
   * @param StorageObject  $object
   * @param string $path
   *
   * @return array
   */
  protected function normalizeObject(StorageObject $object, $path = null)
  {
      if(!$path) $path = $object->name;

      $path = ltrim($path, $this->getPathPrefix());

      if ($object->contentType == 'application/directory') {
        return ['type' => 'dir', 'path' => trim($path, '/')];
      }

      $result = Util::map((array) $object, static::$resultMap);

      if ($object->lastModified)  $result['timestamp'] = $object->lastModified->getTimestamp();

      $result['type'] = 'file';
      $result['path'] = trim($path, '/');
      return $result;
  }

  /**
  * @inheritdoc
  */
  public function getMetadata($path)
  {
    return $this->getObjectArray($path);
  }

  /**
  * @inheritdoc
  */
  public function getSize($path)
  {
    return $this->getMetadata($path);
  }

  /**
  * @inheritdoc
  */
  public function getMimetype($path)
  {
    return $this->getMetadata($path);
  }

  /**
  * @inheritdoc
  */
  public function getTimestamp($path)
  {
    return $this->getMetadata($path);
  }

  /**
  * @inheritdoc
  */
  public function getVisibility($path)
  {
    return $this->getMetadata($path);
  }

  /**
  * @inheritdoc
  */
  public function setVisibility($path, $visibility)
  {
    return false;
  }

  /**
  * @inheritdoc
  */
  public function createDir($dirname, Config $config)
  {
    $location = $this->applyPathPrefix($dirname);
    $headers = $config->get('headers', []);
    $headers['Content-Type'] = 'application/directory';
    $extendedConfig = (new Config())->setFallback($config);
    $extendedConfig->set('headers', $headers);

    return $this->write($location, '', $extendedConfig);
  }

  /**
  * @inheritdoc
  */
  public function deleteDir($dirname)
  {
    return false;
  }
}
