# Laravel 5.4 - Restore Crashed Database Data approach (using Trait)

## Scenario details: ##

- A monolithic application crashes on Friday evening.
- The latest snapshot is from Thursday.
- The snapshot from Thursday is applied after the crash.
- Happily, the crashed database is successfully retrieved from the crashed server.
- However, the restored application database is behind the crashed database.
- Also, new data is constantly submitted to the restored database from Thursday that is now the operational production database.
- Thus, there is a one day gap between the crashed and restored database.

-- The Task is missing data from Friday to be smoothly and correctly restored to the new production database.

-- All the foreign keys should be properly updated with their new related rows IDs.

-- Only the rows that have not been updated (after the Thursday snapshot has been applied) should be updated during the restore process.

*Data is predictibale so, no data validation is needed.

## Steps for successful completion ##

1. The start and the end of the data gap in the current production database should be carefully inspected for each table.
2. All restore steps of each of the database tables should be planned according to the foreign keys changes that have been occured after the crash.
3. All the restore steps should handled using Laravel Commands and a Trait containing all the restore logic.
4. The restore logic should be maximum abstract.
5. All the commands should be manually executed from the console.
6. Supervisor (if any) should be stopped.
7. Application should be put in a maintenance mode - php artisan down
8. A database backup dump of the current operational production database should be made before the execution of the resote commands.
9. All the restore commands should be executed with proper Linux user having read/write permissions in the project folder.
10. Crashed DB connection should be added to the .env and config/database
11. Execute restore commands.
12. Remove crashed DB connection from .env and config/database
13. Investigate restored data
14. Start Supervisor (if any)
15. php artisan up
