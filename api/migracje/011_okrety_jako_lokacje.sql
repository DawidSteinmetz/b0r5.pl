-- Okręty i floty jako miejsca pobytu; wiązanie pozycji NPC z ich profilem SQL.
ALTER TABLE mapa_obiekty
  ADD COLUMN npc_podmiot_id BIGINT UNSIGNED NULL AFTER legacy_uid,
  ADD COLUMN na_pokladzie_id BIGINT UNSIGNED NULL AFTER planeta_id,
  ADD UNIQUE KEY uq_mapa_obiekty_npc (npc_podmiot_id),
  ADD KEY ix_mapa_obiekty_poklad (na_pokladzie_id),
  ADD CONSTRAINT fk_mapa_obiekty_npc FOREIGN KEY (npc_podmiot_id) REFERENCES osoby(podmiot_id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_mapa_obiekty_poklad FOREIGN KEY (na_pokladzie_id) REFERENCES mapa_obiekty(id) ON DELETE SET NULL;

INSERT IGNORE INTO schema_migracje (wersja,opis)
VALUES ('v11-okrety-jako-lokacje','Okręt i okręt flagowy floty jako lokacja postaci; pozycje NPC powiązane z profilem SQL');
