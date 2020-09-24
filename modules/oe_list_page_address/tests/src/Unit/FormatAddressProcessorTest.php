<?php

namespace Drupal\Tests\oe_list_page_address\Unit;

use CommerceGuys\Addressing\Country\Country;
use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\facets\Entity\Facet;
use Drupal\facets\Result\Result;
use Drupal\oe_list_page_address\Plugin\facets\processor\FormatAddressProcessor;
use Drupal\Tests\UnitTestCase;

/**
 * Unit test for processor.
 *
 * @group facets
 */
class FormatAddressProcessorTest extends UnitTestCase {

  /**
   * Tests filtering of results.
   */
  public function testBuild() {
    // Mock country repository.
    $country = new Country([
      'country_code' => 'GB',
      'name' => 'United Kingdom',
      'locale' => 'en_US',
    ]);
    $country_repository = $this->getMockBuilder(CountryRepositoryInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $country_repository->expects($this->any())
      ->method('get')
      ->with('GB')
      ->will($this->returnValue($country));

    $facet = new Facet([], 'facets_facet');
    $processor = new FormatAddressProcessor([], 'format_address', [], $country_repository);

    $original_results = [
      new Result($facet, 'GB', 0, 10),
    ];
    $facet->setResults($original_results);

    $filtered_results = $processor->build($facet, $original_results);
    $this->assertEquals('United Kingdom', $filtered_results[0]->getDisplayValue());
  }

}
