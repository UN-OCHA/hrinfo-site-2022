<?php

namespace Drupal\hr_paragraphs\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\date_recur\DateRecurHelper;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Page controller for tabs.
 */
class ParagraphController extends ControllerBase {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The HTTP client to fetch the files with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The pager manager servie.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * The pager parameters service.
   *
   * @var \Drupal\Core\Pager\PagerParametersInterface
   */
  protected $pagerParameters;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManager $entity_type_manager, ClientInterface $http_client, PagerManagerInterface $pager_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->httpClient = $http_client;
    $this->pagerManager = $pager_manager;
  }

  /**
   * Helper to check if tab is active.
   */
  protected function tabIsActive($group, $tab) {
    if (is_numeric($group)) {
      $group = $this->entityTypeManager->getStorage('group')->load($group);
    }

    if (!$group) {
      return AccessResult::forbidden();
    }

    $enabled_tabs = $group->field_enabled_tabs->getValue();
    array_walk($enabled_tabs, function (&$item) {
      $item = $item['value'];
    });

    return AccessResult::allowedIf(in_array($tab, $enabled_tabs));
  }

  /**
   * Check if offices is enabled.
   */
  public function hasOffices($group) {
    return $this->tabIsActive($group, 'offices');
  }

  /**
   * Check if assessments is enabled.
   */
  public function hasAssessments($group) {
    return $this->tabIsActive($group, 'assessments');
  }

  /**
   * Check if datasets is enabled.
   */
  public function hasDatasets($group) {
    return $this->tabIsActive($group, 'datasets');
  }

  /**
   * Check if documents is enabled.
   */
  public function hasDocuments($group) {
    return $this->tabIsActive($group, 'documents');
  }

  /**
   * Check if maps is enabled.
   */
  public function hasInfographics($group) {
    return $this->tabIsActive($group, 'maps');
  }

  /**
   * Check if events is enabled.
   */
  public function hasEvents($group) {
    $active = $this->tabIsActive($group, 'events');
    if (!$active) {
      return $active;
    }

    if (is_numeric($group)) {
      $group = $this->entityTypeManager->getStorage('group')->load($group);
    }

    return AccessResult::allowedIf(!$group->field_ical_url->isEmpty());
  }

  /**
   * Return all offices of an operation, sector or cluster.
   */
  public function getOffices($group) {
    if ($group->field_operation->isEmpty()) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Operation not set.'),
      ];
    }

    $operation_uuid = $group->field_operation->entity->uuid();

    $entity_id = 'office';
    $view_mode = 'teaser';

    $office_uuids = $this->entityTypeManager->getStorage($entity_id)->getQuery()->condition('operations', $operation_uuid)->execute();
    $offices = $this->entityTypeManager->getStorage($entity_id)->loadMultiple($office_uuids);

    $view_builder = $this->entityTypeManager->getViewBuilder($entity_id);
    return $view_builder->viewMultiple($offices, $view_mode);
  }

  /**
   * Return all datasets of an operation, sector or cluster.
   */
  public function getDatasets($group, Request $request) {
    $limit = 10;
    $offset = 0;

    if ($request->query->has('page')) {
      $offset = $request->query->getInt('page', 0) * $limit;
    }

    if ($group->field_operation->isEmpty()) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Operation not set.'),
      ];
    }

    // Get country.
    $country = $group->field_operation->entity->field_country->entity;

    $endpoint = 'https://data.humdata.org/api/3/action/package_search';
    $parameters = [
      'q' => 'groups:' . strtolower($country->field_iso_3->value),
      'rows' => $limit,
      'start' => $offset,
    ];

    try {
      $response = $this->httpClient->request(
        'GET',
        $endpoint,
        [
          'query' => $parameters,
        ]
      );
    } catch (RequestException $exception) {
      if ($exception->getCode() === 404) {
        throw new NotFoundHttpException();
      }
    }

    $body = $response->getBody() . '';
    $results = json_decode($body, TRUE);

    $count = $results['result']['count'];
    $this->pagerManager->createPager($count, $limit);

    $data = [];
    foreach ($results['result']['results'] as $row) {
      $data[] = [
        'id' => $row['id'],
        'title' => $row['title'],
        'last_modified' => strtotime($row['last_modified']),
        'source' => $row['dataset_source'],
      ];
    }

    return [
      '#theme' => 'hdx_dataset',
      '#data' => $data,
      '#pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Return all documents of an operation, sector or cluster.
   */
  public function getDocuments($group, Request $request) {
    $limit = 10;
    $offset = 0;

    if ($request->query->has('page')) {
      $offset = $request->query->getInt('page', 0) * $limit;
    }

    if ($group->field_operation->isEmpty()) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Operation not set.'),
      ];
    }

    // Get country.
    $country = $group->field_operation->entity->field_country->entity;

    $endpoint = 'https://api.reliefweb.int/v1/reports';
    $parameters = [
      'appname' => 'hrinfo',
      'offset' => $offset,
      'limit' => $limit,
      'preset' => 'latest',
      'fields[include]' => [
        'id',
        'disaster_type.name',
        'url',
        'title',
        'date.changed',
        'source.shortname',
        'country.name',
        'primary_country.name',
        'file.url',
        'file.preview.url-thumb',
        'file.description',
        'file.filename',
        'format.name',
      ],
      'filter' => [
        'operator' => 'AND',
        'conditions' => [],
      ],
    ];

    $parameters['filter']['conditions'][] = array(
      'field' => 'primary_country.iso3',
      'value' => strtolower($country->field_iso_3->value),
      'operator' => 'OR',
    );

    $parameters['filter']['conditions'][] = array(
      'field' => 'format.id',
      'value' => [
        12,
        12570,
      ],
      'operator' => 'OR',
      'negate' => TRUE,
    );

    try {
      $response = $this->httpClient->request(
        'GET',
        $endpoint,
        [
          'query' => $parameters,
        ]
      );
    } catch (RequestException $exception) {
      if ($exception->getCode() === 404) {
        throw new NotFoundHttpException();
      }
    }

    $body = $response->getBody() . '';
    $results = json_decode($body, TRUE);

    $count = $results['totalCount'];
    $this->pagerManager->createPager($count, $limit);

    $data = [];
    foreach ($results['data'] as $row) {
      $url = $row['fields']['url'];
      $title = isset($row['fields']['title']) ? $row['fields']['title'] : $row['fields']['name'];
      $data[$title] = [
        'id' => $row['fields']['id'],
        'title' => $title,
        'url' => $url,
        'date_changed' => $row['fields']['date']['changed'],
        'format' => $row['fields']['format'][0]['name'],
        'primary_country' => $row['fields']['primary_country']['name'],
      ];

      if (isset($row['fields']['source'])) {
        $sources = [];
        foreach ($row['fields']['source'] as $source) {
          $sources[] = $source['shortname'];
        }
        $data[$title]['sources'] = implode(', ', $sources);
      }

      if (isset($row['fields']['disaster_type'])) {
        $disaster_types = [];
        foreach ($row['fields']['disaster_type'] as $disaster_type) {
          $disaster_types[] = $disaster_type['name'];
        }
        $data[$title]['disaster_types'] = $disaster_types;
      }

      if (isset($row['fields']['country'])) {
        $countries = [];
        foreach ($row['fields']['country'] as $country) {
          $countries[] = $country['name'];
        }
        $data[$title]['countries'] = $countries;
      }

      if (isset($row['fields']['file'])) {
        $files = [];
        foreach ($row['fields']['file'] as $file) {
          $files[] = array(
            'preview' => isset($file['preview']['url-thumb']) ? $this->reliefweb_fix_url($file['preview']['url-thumb']) : '',
            'url' => $this->reliefweb_fix_url($file['url']),
            'filename' => isset($file['filename']) ? $file['filename'] : '',
            'description' => isset($file['description']) ? $file['description'] : '',
          );
        }
        $data[$title]['files'] = $files;
      }
    }

    return [
      '#theme' => 'rw_river',
      '#data' => $data,
      '#pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Return all documents of an operation, sector or cluster.
   */
  public function getInfographics($group, Request $request) {
    $limit = 10;
    $offset = 0;

    if ($request->query->has('page')) {
      $offset = $request->query->getInt('page', 0) * $limit;
    }

    if ($group->field_operation->isEmpty()) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Operation not set.'),
      ];
    }

    // Get country.
    $country = $group->field_operation->entity->field_country->entity;

    $endpoint = 'https://api.reliefweb.int/v1/reports';
    $parameters = [
      'appname' => 'hrinfo',
      'offset' => $offset,
      'limit' => $limit,
      'preset' => 'latest',
      'fields[include]' => [
        'id',
        'disaster_type.name',
        'url',
        'title',
        'date.changed',
        'source.shortname',
        'country.name',
        'primary_country.name',
        'file.url',
        'file.preview.url-thumb',
        'file.description',
        'file.filename',
        'format.name',
      ],
      'filter' => [
        'operator' => 'AND',
        'conditions' => [],
      ],
    ];

    $parameters['filter']['conditions'][] = array(
      'field' => 'primary_country.iso3',
      'value' => strtolower($country->field_iso_3->value),
      'operator' => 'OR',
    );

    $parameters['filter']['conditions'][] = array(
      'field' => 'format.id',
      'value' => [
        12,
        12570,
      ],
      'operator' => 'OR',
    );

    try {
      $response = $this->httpClient->request(
        'GET',
        $endpoint,
        [
          'query' => $parameters,
        ]
      );
    } catch (RequestException $exception) {
      if ($exception->getCode() === 404) {
        throw new NotFoundHttpException();
      }
    }

    $body = $response->getBody() . '';
    $results = json_decode($body, TRUE);

    $count = $results['totalCount'];
    $this->pagerManager->createPager($count, $limit);

    $data = [];
    foreach ($results['data'] as $row) {
      $url = $row['fields']['url'];
      $title = isset($row['fields']['title']) ? $row['fields']['title'] : $row['fields']['name'];
      $data[$title] = [
        'id' => $row['fields']['id'],
        'title' => $title,
        'url' => $url,
        'date_changed' => $row['fields']['date']['changed'],
        'format' => $row['fields']['format'][0]['name'],
        'primary_country' => $row['fields']['primary_country']['name'],
      ];

      if (isset($row['fields']['source'])) {
        $sources = [];
        foreach ($row['fields']['source'] as $source) {
          $sources[] = $source['shortname'];
        }
        $data[$title]['sources'] = implode(', ', $sources);
      }

      if (isset($row['fields']['disaster_type'])) {
        $disaster_types = [];
        foreach ($row['fields']['disaster_type'] as $disaster_type) {
          $disaster_types[] = $disaster_type['name'];
        }
        $data[$title]['disaster_types'] = $disaster_types;
      }

      if (isset($row['fields']['country'])) {
        $countries = [];
        foreach ($row['fields']['country'] as $country) {
          $countries[] = $country['name'];
        }
        $data[$title]['countries'] = $countries;
      }

      if (isset($row['fields']['file'])) {
        $files = [];
        foreach ($row['fields']['file'] as $file) {
          $files[] = array(
            'preview' => isset($file['preview']['url-thumb']) ? $this->reliefweb_fix_url($file['preview']['url-thumb']) : '',
            'url' => $this->reliefweb_fix_url($file['url']),
            'filename' => isset($file['filename']) ? $file['filename'] : '',
            'description' => isset($file['description']) ? $file['description'] : '',
          );
        }
        $data[$title]['files'] = $files;
      }
    }

    return [
      '#theme' => 'rw_river',
      '#data' => $data,
      '#pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Fix URL for reliefweb.
   */
  protected function reliefweb_fix_url($url) {
    $url = str_replace('#', '%23', $url);
    $url = str_replace(' ', '%20', $url);
    $url = str_replace('http://', 'https://', $url);

    return $url;
  }

  /**
   * Return all assessments of an operation, sector or cluster.
   */
  public function getAssessments($group, $type = 'list') {
    if ($group->field_operation->isEmpty()) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('Operation not set.'),
      ];
    }

    $operation_uuid = $group->field_operation->entity->uuid();

    global $base_url;
    switch ($type) {
      case 'map':
        $src = $base_url . '/rest/assessments/map-data?f[0]=operations:' . $operation_uuid;
        $theme = 'hr_paragraphs_assessments_map';
        break;

      case 'table':
        $src = $base_url . '/rest/assessments/table-data?f[0]=operations:' . $operation_uuid;
        $theme = 'hr_paragraphs_assessments_table';
        break;

      case 'list':
        $src = $base_url . '/rest/assessments/list-data?f[0]=operations:' . $operation_uuid;
        $theme = 'hr_paragraphs_assessments_list';
        break;

      default:
        $src = $base_url . '/rest/assessments/list-data?f[0]=operations:' . $operation_uuid;
        $theme = 'hr_paragraphs_assessments_list';
        break;

    }

    return [
      '#theme' => $theme,
      '#base_url' => $base_url,
      '#src' => $src,
      '#component_url' => '/modules/custom/hr_paragraphs/component/build/',
    ];
  }

  /**
   * Return all events of an operation, sector or cluster.
   */
  public function getEvents($group) {
    if (is_numeric($group)) {
      $group = $this->entityTypeManager->getStorage('group')->load($group);
    }

    // Settings.
    $settings = [
      'header' => [
        'left' => 'prev,next today',
        'center' => 'title',
        'right' => 'month,agendaWeek,agendaDay,listMonth',
      ],
      'plugins' => [
        'listPlugin',
      ],
      'defaultDate' => date('Y-m-d'),
      'editable' => FALSE,
    ];

    // Set source to proxy.
    $datasource_uri = '/group/' . $group->id() . '/ical';
    $settings['events'] = $datasource_uri;

    return [
      'calendar' => [
        '#theme' => 'fullcalendar_calendar',
        '#calendar_id' => 'fullcalendar',
        '#calendar_settings' => $settings,
      ],
      'calendar_popup' => [
        '#type' => 'inline_template',
        '#template' => '
          <div id="fullCalModal" style="display:none;">
          <div>Date: <span id="modalStartDate"></span> <span id="modalEndDate"></span></div>
          <div><span id="modalDescription"></span></div>
          <div>Location: <span id="modalLocation"></span></div>
          <div><span id="modalAttachments"></span></div>
        </div>',
        '#attached' => [
          'library' => [
            'hr_paragraphs/fullcalendar',
          ],
        ],
      ],
    ];
  }

  /**
   * Proxy iCal requests.
   */
  public function getIcal($group, Request $request) {
    $range_start = $request->query->get('start') ?? date('Y-m-d');
    $range_end = $request->query->get('end') ?? date('Y-m-d', time() + 365 * 24 * 60 * 60);

    // Get iCal URL from group.
    if (is_numeric($group)) {
      $group = $this->entityTypeManager->getStorage('group')->load($group);
    }
    $url = $group->field_ical_url->value;

    // Feych and parse iCal.
    $cal = new CalFileParser();
    $events = $cal->parse($url);

    $output = [];
    foreach ($events as $event) {
      // Collect attachments.
      $attachments = [];
      foreach ($event as $key => $value) {
        if (strpos($key, 'ATTACH;FILENAME=') !== FALSE) {
          $str_length = strlen('ATTACH;FILENAME=');
          $attachments[] = [
            'filename' => substr($key, $str_length, strpos($key, ';', $str_length) - $str_length),
            'url' => $value,
          ];
        }
      }

      if (isset($event['RRULE'])) {
        $iterationCount = 0;
        $maxIterations = 40;

        $rule = DateRecurHelper::create($event['RRULE'], $event['DTSTART'], $event['DTEND']);
        if ($range_start && $range_end) {
          $generator = $rule->generateOccurrences(new \DateTime($range_start), new \DateTime($range_end));
        }
        else {
          $generator = $rule->generateOccurrences(new \DateTime());
        }

        foreach ($generator as $occurrence) {
          $output[] = [
            'title' => $event['SUMMARY'],
            'description' => $event['DESCRIPTION'],
            'location' => $event['LOCATION'],
            'start' => $occurrence->getStart()->format(\DateTimeInterface::W3C),
            'end' => $occurrence->getEnd()->format(\DateTimeInterface::W3C),
            'attachments' => $attachments,
          ];

          $iterationCount++;
          if ($iterationCount >= $maxIterations) {
            break;
          }
        }
      }
      else {
        if ($range_start && $range_end) {
          if ($event['DTSTART']->format('Y-m-d') > $range_end) {
            continue;
          }
          if ($event['DTEND']->format('Y-m-d') < $range_start) {
            continue;
          }

          $output[] = [
            'title' => $event['SUMMARY'],
            'description' => $event['DESCRIPTION'],
            'location' => $event['LOCATION'],
            'start' => $event['DTSTART']->format(\DateTimeInterface::W3C),
            'end' => $event['DTEND']->format(\DateTimeInterface::W3C),
            'attachments' => $attachments,
          ];
        }
        else {
          $output[] = [
            'title' => $event['SUMMARY'],
            'description' => $event['DESCRIPTION'],
            'location' => $event['LOCATION'],
            'start' => $event['DTSTART']->format(\DateTimeInterface::W3C),
            'end' => $event['DTEND']->format(\DateTimeInterface::W3C),
            'attachments' => $attachments,
          ];
        }
      }
    }

    return new JsonResponse($output);
  }

}
