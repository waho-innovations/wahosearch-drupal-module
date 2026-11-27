# Compatibility

| Drupal | PHP | Database | Automated tests | Status |
|---|---|---|---|---|
| 11.4.12 | 8.3 | SQLite | Unit, kernel, functional | Tested |
| 10.6.21 | 8.3 | SQLite | Unit, kernel, functional | Tested |
| 10.2 to 11.x | 8.1 or later | MySQL, MariaDB, PostgreSQL | Not run yet | Declared, not verified |

The module declares `core_version_requirement: ^10.2 || ^11`. Before launch,
check which Drupal versions are still maintained on drupal.org and run the test
suite on those versions with the production database.

To run the tests, see the Tests section of the [README](../README.md).
