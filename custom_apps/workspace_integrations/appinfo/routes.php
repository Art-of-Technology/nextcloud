<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
return ['routes' => [
 ['name'=>'card#show','url'=>'/api/cards/{cardId}','verb'=>'GET'],
 ['name'=>'card#page','url'=>'/cards/{cardId}','verb'=>'GET'],
 ['name'=>'page#index','url'=>'/','verb'=>'GET'],
 ['name'=>'api#capabilities','url'=>'/api/capabilities','verb'=>'GET'],
 ['name'=>'api#channelConnections','url'=>'/api/channels/{token}/connections','verb'=>'GET'],
 ['name'=>'api#disconnectChannel','url'=>'/api/channels/{token}/connections/{connectionId}','verb'=>'DELETE'],
 ['name'=>'api#index','url'=>'/api/integrations','verb'=>'GET'],
 ['name'=>'api#create','url'=>'/api/integrations','verb'=>'POST'],
 ['name'=>'api#update','url'=>'/api/integrations/{id}','verb'=>'PATCH'],
 ['name'=>'api#channels','url'=>'/api/channels','verb'=>'GET'],
 ['name'=>'api#connect','url'=>'/api/integrations/{id}/connections','verb'=>'POST'],
 ['name'=>'api#disconnect','url'=>'/api/integrations/{id}/connections/{connectionId}','verb'=>'DELETE'],
 ['name'=>'api#rotate','url'=>'/api/integrations/{id}/connections/{connectionId}/rotate','verb'=>'POST'],
 ['name'=>'api#hook','url'=>'/hooks/{connectionId}','verb'=>'POST'],
]];
