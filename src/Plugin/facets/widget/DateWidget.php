<?php

declare(strict_types = 1);

namespace Drupal\oe_list_pages\Plugin\facets\widget;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DateHelper;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\facets\FacetInterface;
use Drupal\oe_list_pages\ListPresetFilter;
use Drupal\oe_list_pages\Plugin\facets\query_type\Date;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The date widget that allows to filter by one or two dates.
 *
 * @FacetsWidget(
 *   id = "oe_list_pages_date",
 *   label = @Translation("List pages date"),
 *   description = @Translation("A date filter widget."),
 * )
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class DateWidget extends ListPagesWidgetBase implements TrustedCallbackInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The ID of the facet.
   *
   * @var string
   */
  protected $facetId;

  /**
   * Constructs a date widget object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager, DateFormatterInterface $date_formatter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'date_type' => Date::DATETIME_TYPE_DATE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet) {
    $form['date_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Date type'),
      '#options' => [
        Date::DATETIME_TYPE_DATE => $this->t('Date only'),
        Date::DATETIME_TYPE_DATETIME => $this->t('Date and time'),
      ],
      '#description' => $this->t('Choose the type of date to filter.'),
      '#default_value' => $this->getConfiguration()['date_type'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildDefaultValueForm(array $form, FormStateInterface $form_state, FacetInterface $facet, ListPresetFilter $preset_filter = NULL): array {
    if ($preset_filter) {
      $facet->setActiveItems($preset_filter->getValues());
    }
    $build = $this->doBuildDateWidgetElements($facet, $form, $form_state);
    $build[$facet->id() . '_op']['#required'] = TRUE;
    $build[$facet->id() . '_first_date_wrapper'][$facet->id() . '_first_date']['#required'] = TRUE;
    $build['#element_validate'] = [[$this, 'validateDefaultValueForm']];
    $this->facetId = $facet->id();
    return $build;
  }

  /**
   * Validation handler for the date form elements.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateDefaultValueForm(array $element, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    if (!$triggering_element) {
      return;
    }

    $facet_id = $this->facetId;
    $parents = array_slice($triggering_element['#parents'], 0, -1);
    $values = $form_state->getValue($parents);
    $operator = $values[$facet_id . '_op'] ?? NULL;
    if (!$operator) {
      return;
    }

    if ($operator !== 'bt') {
      return;
    }

    // Check that if we select BETWEEN, we have two dates.
    $second_date = $values[$facet_id . '_second_date_wrapper'][$facet_id . '_second_date'];
    if (!$second_date) {
      $form_state->setError($element[$facet_id . '_second_date_wrapper'][$facet_id . '_second_date'], $this->t('The second date is required.'));
    }

    // Check that if we select BETWEEN, the second date is after the first one.
    $first_date = $values[$facet_id . '_first_date_wrapper'][$facet_id . '_first_date'];
    if ($second_date < $first_date) {
      $form_state->setError($element[$facet_id . '_second_date_wrapper'][$facet_id . '_second_date'], $this->t('The second date cannot be before the first date.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet) {
    return $this->doBuildDateWidgetElements($facet);
  }

  /**
   * Builds the elements for the Date widget.
   *
   * @param \Drupal\facets\FacetInterface $facet
   *   The facet.
   * @param array $form
   *   A form if the widget is built inside a form.
   * @param \Drupal\Core\Form\FormStateInterface|null $form_state
   *   A form state if the widget is built inside a form.
   *
   * @return array
   *   The widget elements.
   */
  protected function doBuildDateWidgetElements(FacetInterface $facet, array $form = [], FormStateInterface $form_state = NULL): array {
    $date_type = $facet->getWidgetInstance()->getConfiguration()['date_type'];

    $operators = [
      'gt' => $this->t('After'),
      'lt' => $this->t('Before'),
      'bt' => $this->t('In between'),
      'ym' => $this->t('By year, month'),
    ];

    $build[$facet->id() . '_op'] = [
      '#type' => 'select',
      '#title' => $facet->getName(),
      '#options' => $operators,
      '#default_value' => $this->getOperatorFromActiveFilters($facet),
      '#empty_option' => $this->t('Select'),
    ];

    $parents = $form['#parents'] ?? [];
    $name = $facet->id() . '_op';
    if ($parents) {
      $first_parent = array_shift($parents);
      $name = $first_parent . '[' . implode('][', array_merge($parents, [$name])) . ']';
    }

    $build[$facet->id() . '_first_date_wrapper'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          [
            ':input[name="' . $name . '"]' => [
              'value' => 'lt',
            ],
          ],
          [
            ':input[name="' . $name . '" ]' => [
              'value' => 'gt',
            ],
          ],
          [
            ':input[name="' . $name . '"]' => [
              'value' => 'bt',
            ],
          ],
        ],
      ],
    ];

    $first_date_default = $this->getDateFromActiveFilters($facet, 'first');

    $build[$facet->id() . '_first_date_wrapper'][$facet->id() . '_first_date'] = [
      '#type' => 'datetime',
      '#date_date_element' => 'date',
      '#date_time_element' => $date_type === 'date' ? 'none' : 'time',
      '#date_date_callbacks' => [[$this, 'setTitleDisplayVisible']],
      '#default_value' => $first_date_default,
    ];

    // We only care about the second date if the operator is "bt".
    $second_date_default = NULL;
    if ($this->getOperatorFromActiveFilters($facet) === 'bt') {
      $second_date_default = $this->getDateFromActiveFilters($facet, 'second');
    }

    $build[$facet->id() . '_second_date_wrapper'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      // We only show the second date if the operator is "bt".
      '#states' => [
        'visible' => [
          ':input[name="' . $name . '"]' => [
            'value' => 'bt',
          ],
        ],
      ],
    ];

    $build[$facet->id() . '_second_date_wrapper'][$facet->id() . '_second_date'] = [
      '#type' => 'datetime',
      '#date_date_element' => 'date',
      '#date_time_element' => $date_type === 'date' ? 'none' : 'time',
      '#date_date_callbacks' => [
        [$this, 'setTitleDisplayVisible'],
        [$this, 'setEndDateTitle'],
      ],
      '#default_value' => $second_date_default,
    ];

    // Builds the filter by year and month for the Date widget.
    $this->doBuildYearMonthFilter($build, $name, $parents, $facet);

    $build['#cache']['contexts'] = [
      'url.query_args',
      'url.path',
    ];

    return $build;
  }

  /**
   * Builds the filter by year and month for the Date widget.
   *
   * @param array $build
   *   The modified widget element.
   * @param string $name
   *   The name of the operator element.
   * @param array $parents
   *   Array of element parents.
   * @param \Drupal\facets\FacetInterface $facet
   *   The facet.
   *
   * @SuppressWarnings(PHPMD.CyclomaticComplexity)
   * @SuppressWarnings(PHPMD.NPathComplexity)
   */
  protected function doBuildYearMonthFilter(array &$build, $name, array $parents, FacetInterface $facet): void {
    $year_months = $this->getYearMonths($facet);
    $year_month_wrapper = $facet->id() . '_year_month_wrapper';
    $build[$year_month_wrapper] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['oe-list-pages-date-widget-wrapper'],
        'data-year-months' => json_encode($year_months),
      ],
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          [
            ':input[name="' . $name . '"]' => [
              'value' => 'ym',
            ],
          ],
        ],
      ],
    ];
    $first_date_default = $this->getDateFromActiveFilters($facet, 'first');
    $default_year = (!empty($first_date_default) ? (int) $first_date_default->format('Y') : NULL);
    $default_month = (!empty($first_date_default) ? (int) $first_date_default->format('m') : NULL);
    if (empty($second_date_default)) {
      $second_date_default = $this->getDateFromActiveFilters($facet, 'second');
    }
    $last_month = (!empty($second_date_default) ? (int) $second_date_default->format('m') : NULL);
    if ($default_month !== $last_month) {
      $default_month = NULL;
    }
    $options_year = ['' => $this->t('Year')];
    foreach ($year_months as $year => $months) {
      $options_year[$year] = $year;
    }
    if (empty($default_year) || empty($options_year[$default_year])) {
      $default_year = NULL;
    }
    if (empty($default_year)) {
      $default_month = NULL;
    }
    $year_element_name = $facet->id() . '_year';
    $month_element_name = $facet->id() . '_month';
    $build[$year_month_wrapper][$year_element_name] = [
      '#type' => 'select',
      '#attributes' => ['class' => ['oe-list-pages-date-widget-year']],
      '#title' => $this->t('Year'),
      '#options' => $options_year,
      '#default_value' => $default_year,
    ];
    $year_selector = $year_month_wrapper . '[' . $year_element_name . ']';
    if ($parents) {
      $first_parent = array_shift($parents);
      $element_names = [$year_month_wrapper, $year_element_name];
      $year_selector = $first_parent . '[' . implode('][', array_merge($parents, $element_names)) . ']';
    }
    $options_month = DateHelper::monthNames();
    $options_month[''] = $this->t('Month');
    $build[$year_month_wrapper][$month_element_name] = [
      '#type' => 'select',
      '#attributes' => ['class' => ['oe-list-pages-date-widget-month']],
      '#title' => $this->t('Month'),
      '#options' => $options_month,
      '#default_value' => $default_month,
      '#states' => [
        'visible' => [
          [
            ':input[name="' . $year_selector . '"]' => [
              'filled' => TRUE,
            ],
          ],
        ],
      ],
    ];
    $build['#attached']['library'][] = 'oe_list_pages/date_widget';
  }

  /**
   * Get list of years and months from data.
   */
  protected function getYearMonths(FacetInterface $facet) {
    $year_months = [];
    // Get the entity type id, bundle and field name for the query.
    [, $entity_type_id, $bundle] = explode(':', $facet->getFacetSourceId());
    $bundle_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle');
    $field_name = $facet->getFieldIdentifier();

    // Build the Search API query.
    $index = $facet->getFacetSource()->getIndex();
    $query = $index->query();
    $fields = $index->getFields();
    if (in_array($bundle_key, array_keys($fields))) {
      $query->addCondition($bundle_key, $bundle);
    }
    $query->addCondition('search_api_datasource', 'entity:' . $entity_type_id);
    $query->range(0, 100000);

    // Load list of years and months from indexed data.
    foreach ($query->execute() as $item) {
      if (!($field = $item->getField($field_name)) || !($values = $field->getValues()) || empty($values[0])) {
        continue;
      }
      [$year, $month] = explode(':', $this->dateFormatter->format($values[0], 'custom', 'Y:m'));
      $year_months[$year][(int) $month] = (int) $month;
    }

    // Sort years and months.
    ksort($year_months);
    foreach ($year_months as $year => $months) {
      ksort($year_months[$year]);
    }
    return $year_months;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareValueForUrl(FacetInterface $facet, array &$form, FormStateInterface $form_state): array {
    $value_keys = [
      'operator' => $facet->id() . '_op',
      'first_date' => [
        $facet->id() . '_first_date_wrapper',
        $facet->id() . '_first_date',
      ],
      'second_date' => [
        $facet->id() . '_second_date_wrapper',
        $facet->id() . '_second_date',
      ],
      'year_date' => [
        $facet->id() . '_year_month_wrapper',
        $facet->id() . '_year',
      ],
      'month_date' => [
        $facet->id() . '_year_month_wrapper',
        $facet->id() . '_month',
      ],
    ];

    $values = [];
    $operator = $form_state->getValue($value_keys['operator'], NULL);
    if (!$operator) {
      return [];
    }

    $values[] = $operator;
    if ($operator !== 'bt') {
      unset($value_keys['second_date']);
    }
    unset($value_keys['operator']);

    // Prepare URL values depending on operator.
    $values = array_merge($values, $this->prepareOperatorValues($operator, $value_keys, $form_state));

    if (count($values) === 1) {
      // If we only have the operator, it means no dates have been specified.
      return [];
    }

    if ($operator === 'bt' && count($values) === 2) {
      // If we are missing one of the date values, we cannot do a BETWEEN
      // filter.
      return [];
    }

    return [implode('|', $values)];
  }

  /**
   * Prepare URL values depending on operator.
   *
   * @param string $operator
   *   The current operator selected by user.
   * @param array $value_keys
   *   Array of elenent keys per operator.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   Array of values to be added to the URL.
   */
  protected function prepareOperatorValues($operator, array $value_keys, FormStateInterface $form_state): array {
    $values = [];
    if ($operator === 'ym') {
      $year = $form_state->getValue($value_keys['year_date']);
      if (!empty($year)) {
        $month = $form_state->getValue($value_keys['month_date']);
        $value = $year . '-' . ($month ?: '01') . '-01T00:00:00';
        $value = new DrupalDateTime($value);
        $days_in_month = $value->format('t');
        $values[] = $value->format(\DateTimeInterface::ATOM);
        $value = $year . '-' . ($month ?: '12') . '-' . $days_in_month . 'T23:59:59';
        $value = new DrupalDateTime($value);
        $values[] = $value->format(\DateTimeInterface::ATOM);
      }
      return $values;
    }
    foreach ($value_keys as $key) {
      $value = $form_state->getValue($key);
      if (!$value) {
        continue;
      }
      if (!$value instanceof DrupalDateTime) {
        $value = new DrupalDateTime($value);
      }
      $values[] = $value->format(\DateTimeInterface::ATOM);
    }
    return $values;
  }

  /**
   * Returns the operator from the active filter.
   *
   * @param \Drupal\facets\FacetInterface $facet
   *   The facet to check the active items from.
   *
   * @return string|null
   *   The operator.
   */
  public function getOperatorFromActiveFilters(FacetInterface $facet): ?string {
    $active_filters = Date::getActiveItems($facet);
    return $active_filters['operator'] ?? NULL;
  }

  /**
   * Returns a date part from the active filter.
   *
   * @param \Drupal\facets\FacetInterface $facet
   *   The facet to check the active items from.
   * @param string $part
   *   The date part to return.
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime|null
   *   The date object.
   */
  public function getDateFromActiveFilters(FacetInterface $facet, string $part): ?DrupalDateTime {
    $active_filters = Date::getActiveItems($facet);
    return $active_filters[$part] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryType() {
    return 'date_comparison';
  }

  /**
   * Sets title visible.
   *
   * @param array $element
   *   The form element whose value is being processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $date
   *   The date object.
   */
  public function setTitleDisplayVisible(array &$element, FormStateInterface $form_state, ?DrupalDateTime $date) {
    $element['date']['#title_display'] = 'before';
  }

  /**
   * Sets title label for end date element.
   *
   * @param array $element
   *   The form element whose value is being processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $date
   *   The date object.
   */
  public function setEndDateTitle(array &$element, FormStateInterface $form_state, ?DrupalDateTime $date) {
    $element['date']['#title'] = $this->t('End Date');
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return [
      'setTitleDisplayVisible',
      'setEndDateTitle',
    ];
  }

}
