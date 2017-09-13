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

    // Include any library packages in the make file.
    $library_types = [
      'drupal-library',
      'bower-asset',
      'npm-asset',
    ];
    $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();

    // Drop the vendor prefixes.
    foreach ($packages as $package) {
      if (in_array($package->getType(), $library_types)) {
        $old_key = $package->getName();
        $new_key = basename($old_key);
        $make['libraries'][$new_key] = $make['libraries'][$old_key];
        unset($make['libraries'][$old_key]);
      }
    }

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
    $info = array(
      'core' => array(),
      'api' => 2,
      'defaults' => array(
        'projects' => array(
          'subdir' => 'contrib',
        ),
      ),
      'projects' => array(),
      'libraries' => array(),
    );

    // The make generation function requires that projects be grouped by type,
    // or else duplicative project groups will be created.
    $core = array();
    $modules = array();
    $themes = array();
    $profiles = array();
    $libraries = array();
    foreach ($this->locker->getLockData()['packages'] as $package) {
      if (strpos($package['name'], 'drupal/') === 0 && in_array($package['type'], array('drupal-core', 'drupal-theme', 'drupal-module', 'drupal-profile'))) {
        $project_name = str_replace('drupal/', '', $package['name']);

        switch ($package['type']) {
          case 'drupal-core':
            $project_name = 'drupal';
            $group =& $core;
            $group[$project_name]['type'] = 'core';
            $info['core'] = substr($package['version'], 0, 1) . '.x';
            break;
          case 'drupal-theme':
            $group =& $themes;
            $group[$project_name]['type'] = 'theme';
            break;
          case 'drupal-module':
            $group =& $modules;
            $group[$project_name]['type'] = 'module';
            break;
          case 'drupal-profile':
            $group =& $profiles;
            $group[$project_name]['type'] = 'profile';
            break;
        }

        $group[$project_name]['download']['type'] = 'git';
        $group[$project_name]['download']['url'] = $package['source']['url'];
        // Dev versions should use git branch + revision, otherwise a tag is used.
        if (strstr($package['version'], 'dev')) {
          // 'dev-' prefix indicates a branch-alias. Stripping the dev prefix from
          // the branch name is sufficient.
          // @see https://getcomposer.org/doc/articles/aliases.md
          if (strpos($package['version'], 'dev-') === 0) {
            $group[$project_name]['download']['branch'] = substr($package['version'], 4);
          }
          // Otherwise, leave as is. Version may already use '-dev' suffix.
          else {
            $group[$project_name]['download']['branch'] = $package['version'];
          }
          $group[$project_name]['download']['revision'] = $package['source']['reference'];
        }
        elseif ($package['type'] == 'drupal-core') {
          // For 7.x tags, replace 7.xx.0 with 7.xx.
          if ($info['core'] == '7.x') {
            $group[$project_name]['download']['tag']= substr($package['version'], 0, 4);
          }
          else {
            $group[$project_name]['download']['tag'] = $package['version'];
          }
        }
        else {
          // Make tag versioning drupal-friendly. 8.1.0-alpha1 => 8.x-1.0-alpha1.
          $major_version = substr($package['version'], 0 ,1);
          $the_rest = substr($package['version'], 2, strlen($package['version']));
          $group[$project_name]['download']['tag'] = "$major_version.x-$the_rest";
        }

        if (!empty($package['extra']['patches_applied'])) {
          foreach ($package['extra']['patches_applied'] as $desc => $url) {
            $group[$project_name]['patch'][] = $url;
          }
        }
      }
      // Include any non-drupal libraries that exist in both .lock and .json.
      elseif (!in_array($package['type'], array('composer-plugin', 'metapackage'))
        && array_key_exists($package['name'], $this->rootPackage->getRequires())) {
        $project_name = $package['name'];
        $libraries[$project_name]['type'] = 'library';
        $libraries[$project_name]['download']['type'] = 'git';
        $libraries[$project_name]['download']['url'] = $package['source']['url'];
        $libraries[$project_name]['download']['branch'] = $package['version'];
        $libraries[$project_name]['download']['revision'] = $package['source']['reference'];
      }
    }

    $info['projects'] = $core + $modules + $themes;
    $info['libraries'] = $libraries;

    return $info;
  }

}
