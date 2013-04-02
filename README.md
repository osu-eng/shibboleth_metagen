Shibboleth Metadata Generator
==================

==Why?
You might want to use this code if you have a lot of Shibboleth enabled Drupal sites
managed via the Aegir Hosting Framework.

Combined with some other modules and coordination with your IdP, adding a new shibboleth 
enabled site could be as simple as creating a new site in Aegir and checking the "use shibboleth"
checkbox.

==What?
This script helps generates a signed shibboleth metadata file from multiple Aegir instances.
The file contains a list of all the assertion consumer service urls allowed by our service providers.
In a typical environment, this file would be automatically picked up by whoever runs the Identity Provider to 
periodically authorize new sites.

This script is designed to be run in a stand alone fashion, querying multiple Aegir instances
as necessary. To retrieve information from Aegir, two custom hosting/provision modules are used:

* hosting_flags (http://source.engineering.osu.edu/project/hosting_flags)
* hosting_api (http://source.engineering.osu.edu/project/hosting_api)


