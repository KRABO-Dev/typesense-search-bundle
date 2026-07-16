<?php
/**
 * @author Jaap Jansma <jaap.jansma@civicoop.org>
 * @license AGPL-3.0
 */

namespace Krabo\TypesenseSearchBundle\Controller;

use Contao\CoreBundle\Controller\AbstractController;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use Doctrine\DBAL\Connection;
use StringUtil;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/typesense/analytics", defaults={"_scope" = "frontend", "_token_check" = false})
 */
class AnalyticsController extends AbstractController {

  private Connection $connection;
  private ContaoFramework $contaoFramework;

  public function __construct(Connection $connection, ContaoFramework $contaoFramework) {
    $this->connection = $connection;
    $this->contaoFramework = $contaoFramework;
    $this->contaoFramework->initialize();
  }

  /**
   * Handles caching the product XML
   *
   * @return Response
   *
   * @Route("/push", name="typesense_analytics_push")
   */
  public function push(Request $request) {
    $data = StringUtil::decodeEntities(Input::get('data', FALSE));
    $data = json_decode($data, TRUE);
    if (strlen($data['q']) < 4) {
      return new JsonResponse(['id' => 0]);
    }
    if (!empty($data['id'])) {
      $result = $this->connection->executeQuery("SELECT * FROM `tl_typesense_analytics` WHERE `id` = ?", [$data['id']]);
      if ($record = $result->fetchAssociative()) {
        if (!str_starts_with($data['q'], $record['q']) && (!str_starts_with($record['q'], $data['q']) || (strlen($record['q']) != (strlen($data['q']+1))))) {
          unset($data['id']);
        }
      }
    }
    $data['tstamp'] = time();
    $data['ip'] = $request->getClientIp();
    $data['result_detail'] = json_encode($data['result_detail']);
    if (!empty($data['id'])) {
      $id = $data['id'];
      unset($data['id']);
      $this->connection->update('tl_typesense_analytics', $data, ['id' => $id]);
    } else {
      $this->connection->insert('tl_typesense_analytics', $data);
      $id = $this->connection->lastInsertId();
    }
    return new JsonResponse(['id' => $id]);
  }

    /**
   * Handles caching the product XML
   *
   * @return Response
   *
   * @Route("/click", name="typesense_analytics_click")
   */
  public function click() {
    $data = StringUtil::decodeEntities(Input::get('data', FALSE));
    $data = json_decode($data, TRUE);
    if (isset($data['id'])) {
      $id = $data['id'];
      unset($data['id']);
      $this->connection->update('tl_typesense_analytics', $data, ['id' => $id]);
    }
    return new JsonResponse([]);
  }

}