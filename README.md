## Sub-split of Flysystem for infomanaik swiss hosting for kDrive / SwissBackup / Public Cloud.

> ⚠️ this is a sub-split, for pull requests and issues, visit: https://github.com/thephpleague/flysystem

```bash
composer require wgr/flysystem-infomaniak
```

doc is coming bro

```php
// CONFIG
$osClient = new OpenStackClient([
  'authUrl' => 'https://api.pub1.infomaniak.cloud/identity/v3',
  'region'  => 'dc3-a',
  'application_credential' => [
    'id' => 'XXXX',
    'secret' => "XXX",
  ],
  'scope'   => ['project' => ['id' => 'XXX']]
]);

// SETUP
$adapter = new Wgr\Flysystem\Infomaniak\Adapter\OpenStack($osClient,'my-container','myFolder');
$filesystem = new League\Flysystem\Filesystem($adapter);

// USAGE
$filesystem->write($path, $contents);
```
