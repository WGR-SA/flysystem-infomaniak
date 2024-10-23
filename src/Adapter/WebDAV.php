<?php

namespace Wgr\Flysystem\Infomaniak\Adapter;

use League\Flysystem\Config;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\WebDAV\WebDAVAdapter;
use League\Flysystem\PathPrefixer;

class WebDAV extends WebDAVAdapter
{
    /**
     * Write using a stream.
     *
     * @throws UnableToWriteFile
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $contents = stream_get_contents($contents);
            $this->write($path, $contents, $config);
        } catch (\Exception $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Write a file.
     *
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($this->encodePath($path));

        try {
            // Ensure parent directory exists
            $dirname = dirname($path);
            if ($dirname !== '' && $dirname !== '.') {
                $this->createDirectory($dirname, $config);
            }

            $response = $this->client->request('PUT', $location, $contents);

            if ($response['statusCode'] >= 400) {
                throw new \RuntimeException('Unable to write file: ' . $response['statusCode']);
            }
        } catch (\Exception $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Encode the path.
     */
    private function encodePath(string $path): string
    {
        $path = rawurlencode($path);
        return str_replace('%2F', '/', $path);
    }
}