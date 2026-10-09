INSERT INTO llx_c_paiement (entity, code, libelle, type, active, module, position)
SELECT __ENTITY__, 'DVOUCH', 'Bon d''achat DoliVoucher', 2, 1, 'dolivoucher', 90
WHERE NOT EXISTS (SELECT 1 FROM llx_c_paiement WHERE entity=__ENTITY__ AND code='DVOUCH');
