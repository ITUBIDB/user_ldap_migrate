<?php
namespace OCA\User_LDAP_Migrate\AppInfo;
use \OCP\AppFramework\App;
class Application extends App {
	public function __construct(array $urlParams=[]) {
		parent::__construct('user_ldap_migrate', $urlParams);
	}
}