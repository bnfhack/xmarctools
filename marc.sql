PRAGMA encoding = 'UTF-8';
PRAGMA page_size = 8192;  -- blob optimisation https://www.sqlite.org/intern-v-extern-blob.html
-- PRAGMA foreign_keys = ON;
-- The VACUUM command may change the ROWIDs of entries in any tables that do not have an explicit INTEGER PRIMARY KEY

CREATE TABLE document (
  -- document
  ark         TEXT NOT NULL, -- cote BNF dans le catalogue
  title       TEXT,    -- 245$a titre du document
  date        TEXT, -- 260$d tel quel, ex : 1760-1780
  year        INTEGER, -- 260$d 1 année de publication
  place       TEXT,    -- 260$a lieu de publication
  publisher   TEXT,    -- 260$c éditeur extrait de l’adresse éditoriale
  dewey       INTEGER, -- 680$a classement
  lang        TEXT,    -- 041$a langue principale
  type        TEXT,    -- 245$d
  description TEXT,    -- 280$a description matérielle
  volumes     INTEGER, -- nombre de volumes gallica
  pages       INTEGER, -- tiré de la description
  size        INTEGER, -- 280$d in- : 8, 4, 12… peu fiable

  byline      TEXT,    -- 100, 700
  bysort      TEXT,    -- 100, 700
  person      INTEGER  REFERENCES person(id), -- 100$3 lien vers auteur principal, notamment pour retrouver des dates
  birthyear   INTEGER, -- 100$d date de naissance de l’auteur principal
  deathyear   INTEGER, -- 100$d date de mort de l’auteur principal, redondance
  posthum     BOOLEAN, -- si l’auteur principal est mort à la date d’édition

  hasgall     BOOLEAN, -- si le document a une version numérisée gallica

  note        TEXTE,   -- autres contenus textuels

  id          INTEGER, -- identifiant BNF
  PRIMARY KEY( id ASC )
);
CREATE UNIQUE INDEX document_ark ON document( ark );
CREATE INDEX document_year ON document( year, pages );
CREATE INDEX document_birthyear ON document( birthyear );

CREATE VIRTUAL TABLE title USING FTS3 (
  -- recherche dans les mots du titres
  text        TEXT  -- exact text
);

CREATE TABLE gallica (
  -- lien d’une notice de document à son texte
  document    INTEGER REFERENCES document(id), -- lien au document par son rowid
  ark         TEXT, -- 937$j
  title       TEXT, -- 937$k
  file        TEXT,
  id          INTEGER, -- identifiant gallica
  PRIMARY KEY(id ASC)
);
CREATE INDEX gallica_document ON gallica( document );


CREATE TABLE person (
  -- Personne, tiré des notices biblio (100, 700), puis autorité
  ark         TEXT, -- 100$3 cote BNF
  family      TEXT NOT NULL, -- 100$a nom de famille
  given       TEXT, -- prénom 100$m
  sort        TEXT NOT NULL, -- version ASCII bas de casse du nom
  gender      INTEGER, -- inférence d’un sexe sur le prénom

  date        TEXT, -- 100$d
  birthyear   INTEGER, -- année de naissance exacte lorsque possible
  deathyear   INTEGER, -- année de mort exacte lorsque possible

  note        TEXT, -- un text de note
  writes      BOOLEAN, -- cache, docs>0, efficace dans un index
  docs        INTEGER, -- cache, nombre de documents dont la personne est auteur principal
  pages       INTEGER, -- cache, nombre de pages dont la personne est auteur principal
  posthum     INTEGER, -- cache, nombre de "docs" attribués après la mort
  anthum      INTEGER, -- cache, nombre de "docs" attribués avant la mort

  id          INTEGER, -- rowid auto
  PRIMARY KEY( id ASC )
);

CREATE UNIQUE INDEX person_ark ON person( ark );
CREATE INDEX person_sort ON person( sort, docs );
CREATE INDEX person_pages ON person( pages );

CREATE TABLE contribution (
  -- lien d’une personne à un document
  document     INTEGER REFERENCES document(id), -- lien au document par son rowid
  person       INTEGER REFERENCES person(id), -- lien à une œuvre, par son rowid
  role         INTEGER REFERENCES role(id), -- nature de la responsabilité
  writes       BOOLEAN, -- redondant avec le code de rôle, mais efficace
  date         INTEGER, -- redondant avec la date de document, mais nécessaire
  id           INTEGER, -- rowid auto
  PRIMARY KEY( id ASC )
);
CREATE UNIQUE INDEX contribution_document ON contribution( document, person, writes );
CREATE UNIQUE INDEX contribution_person ON contribution( person, document, writes );
CREATE INDEX contribution_person2 ON contribution( person, writes, date );
CREATE INDEX contribution_role ON contribution( role, person );
-- utile pour affecter document.deathyear, document.birthyear
CREATE INDEX contribution_writes2 ON contribution( writes, document, person );


CREATE TABLE dewey (
  -- TODO gérer le champ 'parent' (cat dewey -- 1er chiffre du code) pour filtrer les résultats
  code        TEXT UNIQUE NOT NULL,     -- ! code dewey sur 3 chiffres (conserver les préfixes'0') (680$a)
  label       TEXT NOT NULL             -- ! libellé du code
);
CREATE UNIQUE INDEX dewey_code ON dewey( code );
CREATE INDEX dewey_label ON dewey( label );
--CREATE INDEX dewey_parent ON dewey(parent);

CREATE TABLE role (
  -- Table BNF des rôles http://data.bnf.fr/vocabulary/roles/
  label        TEXT NOT NULL, -- ! libellé du code
  creator      BOOLEAN, -- ! rôle majeur
  url          TEXT, -- ! libellé du code
  id           INTEGER, -- rowid auto
  PRIMARY KEY( id ASC )
)
