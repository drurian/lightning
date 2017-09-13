<?php

namespace Acquia\Lightning\Composer;

use Acquia\Lightning\IniEncoder;
use Composer\Package\Locker;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event;

/**
 * Generates Drush make files for drupal.org's ancient packaging system.
 */
class Package {

  protected $rootPackage;

  protected $locker;

  public function __construct(RootPackageInterface $root_package, Locker $locker) {
    $this->rootPackage = $root_package;
    $this->locker = $locker;
  }

  /**
   * Script entry point.
   *
   * @param \Composer\Script\Event $event
   *   The script event.
   */
  public static function execute(Event $event) {
    $composer = $event->getComposer();

    $handler = new static(
      $composer->getPackage(),
      $composer->getLocker()
    );

    $encoder = new IniEncoder();

    $make = $handler->convert();

    if (isset($make['projects']['drupal'])) {
      // Always use drupal.org's core repository, or patches will not apply.
      $make['projects']['drupal']['download']['url'] = 'https://git.drupal.org/project/drupal.git';

      $core = [
        'api' => 2,
        'core' => '8.x',
        'projects' => [
          'drupal' => [
            'type' => 'core',
            'version' => $make['projects']['drupal']['download']['tag'],
          ],
        ],
      ];
      if (isset($make['projects']['drupal']['patch'])) {
        $core['projects']['drupal']['patch'] = $make['projects']['drupal']['patch'];
      }
      file_put_contents('drupal-org-core.make', $encoder->encode($core));
      unset($make['projects']['drupal']);
    }

    foreach ($make['projects'] as $key => &$project) {
      if ($project['download']['type'] == 'git') {
        $tag = $project['download']['tag'];
        preg_match('/\d+\.x-\d+\.0/', $tag, $match);
        $tag = str_replace($match, str_replace('x-', NULL, $match), $tag);
        preg_match('/\d+\.\d+\.0/', $tag, $match);
        $tag = str_replace($match, substr($match[0], 0, -2), $tag);
        $project['version'] = $tag;
        unset($project['download']);
      }
    }

    file_put_contents('drupal-org.make', $encoder->encode($make));
  }

  protected function convert() {
    $info = [
      'core' => '8.x',
      'api' => 2,
      'defaults' => [
        'projects' => [
          'subdir' => 'contrib',
        ],
      ],
      'projects' => [],
      'libraries' => [],
    ];

    // The make generation function requires that projects be grouped by type,
    // or else duplicative project groups will be created.
    $projects = $libraries = [];
    foreach ($this->locker->getLockData()['packages'] as $package) {
      if ($this->isDrupalPackage($package)) {
        $project_name = str_replace('drupal/', '', $package['name']);

        if ($package['type'] == 'drupal-core') {
          $project_name = 'drupal';
        }

        $projects[$project_name] = $this->makeDrupalPackage($package);

        // Dev versions should use git branch + revision, otherwise a tag is used.
        if (strstr($package['version'], 'dev')) {
          // 'dev-' prefix indicates a branch-alias. Stripping the dev prefix from
          // the branch name is sufficient.
          // @see https://getcomposer.org/doc/articles/aliases.md
          if (strpos($package['version'], 'dev-') === 0) {
            $projects[$project_name]['download']['branch'] = substr($package['version'], 4);
          }
          // Otherwise, leave as is. Version may already use '-dev' suffix.
          else {
            $projects[$project_name]['download']['branch'] = $package['version'];
          }
          $projects[$project_name]['download']['revision'] = $package['source']['reference'];
        }
        elseif ($package['type'] == 'drupal-core') {
          $projects[$project_name]['download']['tag'] = $package['version'];
        }
        else {
          // Make tag versioning drupal-friendly. 8.1.0-alpha1 => 8.x-1.0-alpha1.
          $major_version = substr($package['version'], 0 ,1);
          $the_rest = substr($package['version'], 2, strlen($package['version']));
          $projects[$project_name]['download']['tag'] = "$major_version.x-$the_rest";
        }
      }
      // Include any non-drupal libraries that exist in both .lock and .json.
      elseif ($this->isLibrary($package)) {
        list(, $project_name) = explode('/', $package['name'], 2);
        $libraries[$project_name]['type'] = 'library';
        $libraries[$project_name] += $this->makePackage($package);
      }
    }

    $info['projects'] = $projects;
    $info['libraries'] = $libraries;

    return $info;
  }

  protected function makeDrupalPackage(array $package) {
    $info = [];

    switch ($package['type']) {
      case 'drupal-core':
      case 'drupal-theme':
      case 'drupal-module':
        $info['type'] = substr($package['type'], 7);
        break;
    }
    return $info + $this->makePackage($package);
  }

  protected function makePackage(array $package) {
    $info = [
      'download' => [
        'type' => 'git',
        'url' => $package['source']['url'],
        'branch' => $package['version'],
        'revision' => $package['source']['reference'],
      ],
    ];

    if (isset($package['extra']['patches_applied'])) {
      $info['patch'] = array_values($package['extra']['patches_applied']);
    }
    return $info;
  }

  /**
   * Determines if a package is a Drupal core, module, theme, or profile.
   *
   * @param array $package
   *   The package info.
   *
   * @return bool
   *   TRUE if the package is a Drupal core, module, theme, or profile;
   *   otherwise FALSE.
   */
  protected function isDrupalPackage(array $package) {
    $package_types = [
      'drupal-core',
      'drupal-module',
      'drupal-theme',
      'drupal-profile',
    ];
    return (
      strpos($package['name'], 'drupal/') === 0 &&
      in_array($package['type'], $package_types)
    );
  }

  /**
   * Determines if a package is an asset library.
   *
   * @param array $package
   *   The package info.
   *
   * @return bool
   *   TRUE if the package is an asset library, otherwise FALSE.
   */
  protected function isLibrary(array $package) {
    $package_types = [
      'drupal-library',
      'bower-asset',
      'npm-asset',
    ];
    return (
      in_array($package['type'], $package_types) &&
      array_key_exists($package['name'], $this->rootPackage->getRequires())
    );
  }

}
