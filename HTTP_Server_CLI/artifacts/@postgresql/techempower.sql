BEGIN;

CREATE TABLE IF NOT EXISTS World (
   id integer NOT NULL,
   randomNumber integer NOT NULL DEFAULT 0,
   PRIMARY KEY (id)
);

INSERT INTO World (id, randomnumber)
SELECT x.id, least(floor(random() * 10000 + 1), 10000)
FROM generate_series(1, 10000) AS x(id)
WHERE NOT EXISTS (SELECT 1 FROM World);

-- TechEmpower "Caching" test: a distinct table with the World schema, primed
-- into an in-memory cache by the framework (never read from disk under load).
CREATE TABLE IF NOT EXISTS CachedWorld (
   id integer NOT NULL,
   randomNumber integer NOT NULL DEFAULT 0,
   PRIMARY KEY (id)
);

INSERT INTO CachedWorld (id, randomnumber)
SELECT x.id, least(floor(random() * 10000 + 1), 10000)
FROM generate_series(1, 10000) AS x(id)
WHERE NOT EXISTS (SELECT 1 FROM CachedWorld);

CREATE TABLE IF NOT EXISTS Fortune (
   id integer NOT NULL,
   message varchar(2048) NOT NULL,
   PRIMARY KEY (id)
);

INSERT INTO Fortune (id, message) VALUES
   (1, 'fortune: No such file or directory'),
   (2, 'A computer scientist is someone who fixes things that aren''t broken.'),
   (3, 'After enough decimal places, nobody gives a damn.'),
   (4, 'A bad random number generator: 1, 1, 1, 1, 1, 4.33e+67, 1, 1, 1'),
   (5, 'A computer program does what you tell it to do, not what you want it to do.'),
   (6, 'Emacs is a nice operating system, but I prefer UNIX. — Tom Christaensen'),
   (7, 'Any program that runs right is obsolete.'),
   (8, 'A list is only as strong as its weakest link. — Donald Knuth'),
   (9, 'Feature: A bug with seniority.'),
   (10, 'Computers make very fast, very accurate mistakes.'),
   (11, '<script>alert("This should not be displayed in a browser alert box.");</script>'),
   (12, 'フレームワークのベンチマーク')
ON CONFLICT (id) DO NOTHING;

COMMIT;
