# mtdashmore
MultiDashmore - multitenant dashboard.  A good saas panel starter project.

- [x] f3 framework - [FatFree Framework](https://github.com/bcosca/fatfree)
- [x] CoPilot v2.3.3 - [AdminLTE + VueJS](https://github.com/misterGF/CoPilot)
- [x] firebase auth
- [x] REST api starter backend

## intro
In order to support multi-tenant/client/projects, we are defining that the generic term: Project = Client = Tenant = Whatever

Home Controller (index.php)
- to present a login screen.

MainDashboard Controller
- to present a dashboard
- to manage global modules

ProjectDashboard Controller
- to manage a project settings
- to manage a project modules

Modules are your SAAS APPs.

### permissions
* A User has many Projects
* A Project has many Modules
* A User can have access to a Project, but may be excluded from a particular Module.

### user permissions
isAdmin, perms: ['allowp:projectcode:modulecode', 'denyp:projectcode:modulecode']

* permit use of wildcard example - denyp:*
* user are denied to all projects by default, you can allow all by granting - allowp:*

## to run
```
php -S 0.0.0.0:8888 -t public
```

# LICENSE
GPL-3.0 base on our use of FatFree Framework.