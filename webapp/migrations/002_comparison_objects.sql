-- Confronto per oggetti (vedi src/ObjectChange.php): velivoli, navi e
-- veicoli comparsi, spariti o rimasti fra le due riprese di un confronto,
-- con la soglia e le note con cui è stato calcolato. NULL per i confronti
-- fatti prima, o quando il confronto per oggetti non è stato chiesto.
ALTER TABLE comparisons ADD COLUMN objects_json TEXT;
