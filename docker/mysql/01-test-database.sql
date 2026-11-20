-- A separate database for the test suite, so running tests never touches
-- the development data in `fortvale`.
CREATE DATABASE IF NOT EXISTS fortvale_test;
GRANT ALL PRIVILEGES ON fortvale_test.* TO 'fortvale'@'%';
