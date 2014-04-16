<?php
/**
 * File containing the Test Setup Factory base class
 *
 * @copyright Copyright (C) 1999-2014 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\API\Repository\Tests\SetupFactory;

use eZ\Publish\Core\Base\WrappedServiceContainer;
use eZ\Publish\Core\Base\Container\Compiler;
use PDO;

/**
 * A Test Factory is used to setup the infrastructure for a tests, based on a
 * specific repository implementation to test.
 */
class LegacySolr extends Legacy
{
    /**
     * Returns a configured repository for testing.
     *
     * @param bool $initializeFromScratch
     *
     * @return \eZ\Publish\API\Repository\Repository
     */
    public function getRepository( $initializeFromScratch = true )
    {
        // Load repository first so all initialization steps are done
        $repository = parent::getRepository( $initializeFromScratch );

        if ( $initializeFromScratch )
        {
            $this->indexAll();
        }

        return $repository;
    }

    protected function getServiceContainer()
    {
        if ( !isset( self::$serviceContainer ) )
        {
            $config = include __DIR__ . "/../../../../../../config.php";
            $installDir = $config['service']['parameters']['install_dir'];

            /** @var \Symfony\Component\DependencyInjection\ContainerBuilder $containerBuilder */
            $containerBuilder = include $installDir . "/eZ/Publish/Core/settings" . "/container_builder.php";

            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateCriterionVisitorPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateFacetBuilderVisitorPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateFieldValueMapperPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateSortClauseVisitorPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\FieldRegistryPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\SignalSlotPass() );

            $containerBuilder->setAlias(
                "ezpublish.api.persistence_handler",
                "ezpublish.spi.persistence.cached_legacy_solr"
            );
            $containerBuilder->setParameter(
                "languages",
                array( "eng-US", "eng-GB" )
            );
            $containerBuilder->setParameter(
                "legacy_dsn",
                self::$dsn
            );

            self::$serviceContainer = new WrappedServiceContainer(
                $installDir,
                $installDir . "/eZ/Publish/Core/settings",
                $installDir . "/var/cache/container",
                true,
                $containerBuilder
            );
        }

        return self::$serviceContainer;
    }

    /**
     * Indexes all Content objects.
     */
    protected function indexAll()
    {
        // @todo: Is there a nicer way to get access to all content objects? We
        // require this to run a full index here.
        /** @var \eZ\Publish\SPI\Persistence\Handler $persistenceHandler */
        $persistenceHandler = $this->getServiceContainer()->get( 'ezpublish.spi.persistence.legacy_solr' );
        /** @var \eZ\Publish\Core\Persistence\Database\DatabaseHandler $databaseHandler */
        $databaseHandler = $this->getServiceContainer()->get( 'ezpublish.api.storage_engine.legacy.dbhandler' );

        $query = $databaseHandler
            ->createSelectQuery()
            ->select( 'id', 'current_version' )
            ->from( 'ezcontentobject' );

        $stmt = $query->prepare();
        $stmt->execute();

        $contentObjects = array();
        while ( $row = $stmt->fetch( PDO::FETCH_ASSOC ) )
        {
            $contentObjects[] = $persistenceHandler->contentHandler()->load(
                $row['id'],
                $row['current_version']
            );
        }

        /** @var \eZ\Publish\Core\Persistence\Solr\Content\Search\Handler $searchHandler */
        $searchHandler = $persistenceHandler->searchHandler();
        $searchHandler->setCommit( false );
        $searchHandler->purgeIndex();
        $searchHandler->setCommit( true );
        $searchHandler->bulkIndexContent( $contentObjects );
    }
}
