--
-- Mucronix Registration: removes the countries and regions table
-- and the plugin data stored in user profiles.
--

DROP TABLE IF EXISTS `#__mucronixregistration_regions`;

DELETE FROM `#__user_profiles` WHERE `profile_key` LIKE 'mucronixregistration.%';
