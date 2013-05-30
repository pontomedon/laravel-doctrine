<?php namespace Atrauzzi\LaravelDoctrine;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Doctrine\ORM\Tools\Setup,
	Doctrine\ORM\Configuration,
	Doctrine\ORM\EntityManager,
	Doctrine\ORM\Mapping\Driver\AnnotationDriver,
	Doctrine\ORM\Mapping\Driver\DriverChain,
	Doctrine\Common\Annotations\AnnotationRegistry,
	Doctrine\Common\Annotations\AnnotationReader,
	Doctrine\Common\Annotations\CachedReader,
	Doctrine\Common\Cache\ArrayCache,
	Doctrine\Common\Cache\ApcCache,
	Doctrine\Common\Cache\MemcacheCache,
	Doctrine\Common\Cache\XcacheCache,
	Doctrine\Common\Cache\RedisCache,
	Doctrine\DBAL\Logging\EchoSQLLogger;

class LaravelDoctrineServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot() {
		$this->package('atrauzzi/laravel-doctrine');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register() {

		//
		// Doctrine
		//
		App::singleton('doctrine', function ($app) {
			/*
			 * Paths & namespaces
			 */
			$model_path = base_path() . '/app/models';
			
			$doctrine_namespace = 'Doctrine';
			$entity_namespace = 'Entity';
			$proxy_namespace = 'Proxies';
			$repository_namespace = 'Repositories';
			$gedmo_namespace = 'Gedmo';
			
			$isDevMode = (App::environment() == 'development');
			
			/*
			 * Create a new Configuration
			 */
			$config = new Configuration;
	
			// Proxy Configuration
			$config->setProxyDir($model_path . '/' . $proxy_namespace);
			$config->setProxyNamespace($proxy_namespace);
			
			// Set up Caches
			if ($isDevMode === false) {
				if (extension_loaded('apc')) {
					$cache = new ApcCache();
				} elseif (extension_loaded('xcache')) {
					$cache = new XcacheCache();
				} elseif (extension_loaded('memcache')) {
					$memcache = new \Memcache();
					$memcache->connect('127.0.0.1');
					$cache = new MemcacheCache();
					$cache->setMemcache($memcache);
				} elseif (extension_loaded('redis')) {
					$redis = new Redis();
					$redis->connect('127.0.0.1');
					$cache = new RedisCache();
					$cache->setRedis($redis);
				} else {
					$cache = new ArrayCache();
				}
			} else {
				$cache = new ArrayCache();
			}
			
			$cache->setNamespace("dc2_" . md5($config->getProxyDir()) . "_"); // to avoid collisions
			
			$config->setMetadataCacheImpl($cache);
			$config->setQueryCacheImpl($cache);
			
			// Set up Metadata Driver
			AnnotationRegistry::registerFile(base_path() . '/vendor/doctrine/orm/lib/Doctrine/ORM/Mapping/Driver/DoctrineAnnotations.php');
			$annotationReader = new AnnotationReader();
			$cachedAnnotationReader = new CachedReader($annotationReader, $cache);
			$annotationDriver = new AnnotationDriver($cachedAnnotationReader, array($model_path . '/' . $entity_namespace));
			$driverChain = new DriverChain();
			// Hook Gedmo into the Metadata Driver
			\Gedmo\DoctrineExtensions::registerAbstractMappingIntoDriverChainORM($driverChain,$cachedAnnotationReader);
			$driverChain->addDriver($annotationDriver, 'Entity');
			$config->setMetadataDriverImpl($driverChain);
			
			// Set up autogenerated proxies
			$config->setAutoGenerateProxyClasses($isDevMode);
			
			/*
			 * add extensions
			 */
			$evm = new \Doctrine\Common\EventManager();
			$softdeleteListener = new \Gedmo\SoftDeleteable\SoftDeleteableListener();
			$softdeleteListener->setAnnotationReader($cachedAnnotationReader);
			$evm->addEventSubscriber($softdeleteListener);
			$config->addFilter('soft-deleteable', 'Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter');
			
			/*
			 * create the Entity Manager
			 */
			$em = EntityManager::create(Config::get('laravel-doctrine::doctrine.connection'), $config, $evm);
			
			/*
			 * enable the soft-deletable filter
			 */
			$em->getFilters()->enable('soft-deleteable');
				
			return $em;
		});

		//
		// Utilities
		//
		App::singleton('doctrine.metadata-factory', function ($app) {
			return App::make('doctrine')->getMetadataFactory();
		});
		App::singleton('doctrine.metadata', function ($app) {
			return App::make('doctrine.metadata-factory')->getAllMetadata();
		});
		App::bind('doctrine.schema-tool', function ($app) {
			return new \Doctrine\ORM\Tools\SchemaTool\SchemaTool(App::make('doctrine'));
		});

		//
		// Commands
		//
		App::bind('doctrine.schema.create', function ($app) {
			return new \Atrauzzi\LaravelDoctrine\Console\CreateSchemaCommand(App::make('doctrine'));
		});
		App::bind('doctrine.schema.update', function ($app) {
			return new \Atrauzzi\LaravelDoctrine\Console\UpdateSchemaCommand(App::make('doctrine'));
		});
		App::bind('doctrine.schema.drop', function ($app) {
			return new \Atrauzzi\LaravelDoctrine\Console\DropSchemaCommand(App::make('doctrine'));
		});
		$this->commands(
			'doctrine.schema.create',
			'doctrine.schema.update',
			'doctrine.schema.drop'
		);

	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides() {
		return array();
	}

}