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

namespace Krabo\TypesenseSearchBundle\Cron;

use Doctrine\DBAL\Connection;
use Contao\CoreBundle\Framework\ContaoFramework;
use Isotope\Model\Product;
use Krabo\TypesenseSearchBundle\Helper\IsotopeProductHelper;
use Krabo\TypesenseSearchBundle\Typesense;
use Psr\Log\LoggerInterface;

class ClearAnalyticsCron {

  private Connection $connection;
  private ContaoFramework $framework;

  /**
   * @param ContaoFramework $contaoFramework
   * @param LoggerInterface|null $logger
   */
  public function __construct(Connection $connection, ContaoFramework $contaoFramework)
  {
    $this->connection = $connection;
    $this->framework = $contaoFramework;
    $this->framework->initialize();
  }

  public function __invoke(): void {
    $this->framework->initialize();
    $timestamp = $GLOBALS['TL_CONFIG']['krabo_typesense_analytics_delete_after'] ?? '2 years ago';
    $tstamp = strtotime($timestamp);
    $this->connection->executeQuery("DELETE FROM `tl_typesense_analytics` WHERE `tstamp` < ?", [$tstamp]);
  }

}