<?php
declare(strict_types=1);

namespace Wgr\Flysystem\Infomaniak\Auth\OpenStack;

use OpenStack\Identity\v3\Api as IdentityApi;

class Api extends IdentityApi
{
  public function __construct()
  {
    $this->params = new Params();
  }

  public function postTokens(): array
  {
    return array_merge(
      parent::postTokens(),
      ['params' => [
          'application_credential' => $this->params->applicationCredential(),
          'methods' => $this->params->methods(),
      ]]
    );
  }
}
