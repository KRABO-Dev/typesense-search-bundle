<?php
/**
 * Copyright (C) 2026  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Krabo\TypesenseSearchBundle;

use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

class Typesense {

  /**
   * @var Typesense
   */
  private static $instance;

  private Client $client;

  private function __construct() {
    if (!empty($GLOBALS['TL_CONFIG']['krabo_typesense_enabled'])) {
      try {
        $this->client = $this->getClient();
      } catch (\Exception $e) {

      }
    }
  }

  public static function getInstance(): Typesense {
    if (!isset(self::$instance)) {
      self::$instance = new Typesense();
    }
    return self::$instance;
  }

  public function getClientConfiguration(): array {
    return [
      'host'     => $GLOBALS['TL_CONFIG']['krabo_typesense_host'],
      'port'     => $GLOBALS['TL_CONFIG']['krabo_typesense_port'],
      'protocol' => $GLOBALS['TL_CONFIG']['krabo_typesense_protocol'],       // For Typesense Cloud use https
      'indexer_api_key' => $GLOBALS['TL_CONFIG']['krabo_typesense_index_api_key'],
      'search_api_key' => $GLOBALS['TL_CONFIG']['krabo_typesense_search_api_key'],
      'collection_prefix' => $GLOBALS['TL_CONFIG']['krabo_typesense_collection_prefix'],
    ];
  }

  public function isEnabled(): bool {
    static $isEnabled;
    if ($isEnabled === null) {
      $isEnabled = false;
      if (!empty($GLOBALS['TL_CONFIG']['krabo_typesense_enabled']) && $this->client) {
        try {
          $health = $this->client->getHealth()->retrieve();
          if ($health) {
            $isEnabled = true;
          }
        } catch (\Exception $e) {
          // Do nothing
        }
      }
    }
    return $isEnabled;
  }

  private function getPrefix() {
    return $this->getClientConfiguration()['collection_prefix'];
  }

  public function deleteCollection(string $collection) {
    try {
      $this->client->collections[$this->getPrefix() . $collection]->delete();
    } catch (\Exception $e) {

    }
  }

  public function removeDocumentFromIndex(string $id, string $collection) {
    try {
      $this->client->collections[$this->getPrefix() . $collection]->documents[$id]->delete();
    } catch (\Exception $e) {

    }
  }

  public function indexDocument(array $document, string $id, string $collection) {
    try {
      $document['id'] = $id;
      $this->client->collections[$this->getPrefix() . $collection]->documents->upsert($document);
    } catch (\Exception $e) {

    }
  }

  public function createCollection(string $collection, array $collectionFields) {
    try {
      $this->client->collections[$this->getPrefix() . $collection]->retrieve();
      $this->client->collections[$this->getPrefix() . $collection]->delete();
    } catch (\Exception $e) {
    }

    try {
      $schema = [
        'name' => $this->getPrefix() . $collection,
        'fields' => $collectionFields
      ];
      $this->client->collections->create($schema);
    } catch (\Exception $e) {
    }
  }

  public function updateCollection(string $collection, array $collectionFields) {
    try {
      $schema = [
        'fields' => $collectionFields
      ];
      $this->client->collections[$this->getPrefix() . $collection]->update($schema);
    } catch (\Exception $e) {
    }
  }

  public function doesCollectionExist(string $collection): bool {
    try {
      $this->client->collections[$this->getPrefix() . $collection]->retrieve();
      return true;
    } catch (\Exception $e) {
    }
    return false;
  }

  public function getCollections($filterByPrefix=true) {
    $prefix = $this->getPrefix();
    $collections = [];
    foreach($this->client->getCollections()->retrieve() as $collection) {
      $name = $collection['name'];
      if ($filterByPrefix) {
        if (str_starts_with($name, $prefix)) {
          $collections[substr($name, strlen($prefix))] = substr($name, strlen($prefix));
        }
      } else {
        $collections[$name] = $name;
      }
    }
    return $collections;
  }

  protected function getClient(): Client {
    $config = $this->getClientConfiguration();
    return new Client(
      [
        'api_key'         => $config['indexer_api_key'],
        'nodes'           => [
          [
            'host'     => $config['host'],
            'port'     => $config['port'],
            'protocol' => $config['protocol'],
          ],
        ],
        'connection_timeout_seconds' => 2,
      ]
    );
  }

}