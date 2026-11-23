-- A separate schema for the test suite, so running tests never touches the
-- development data in the FORTVALE schema. Runs once, in the FREEPDB1 database.
ALTER SESSION SET CONTAINER = FREEPDB1;
CREATE USER fortvale_test IDENTIFIED BY fortvale QUOTA UNLIMITED ON USERS;
GRANT CREATE SESSION, CREATE TABLE, CREATE SEQUENCE, CREATE TRIGGER, CREATE VIEW, CREATE PROCEDURE, CREATE TYPE TO fortvale_test;
