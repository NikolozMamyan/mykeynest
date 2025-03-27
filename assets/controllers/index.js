import { Application } from "@hotwired/stimulus";
import AuthController from "./auth_controller.js";
import BattleController from "./battle_controller.js";
import InventoryController from "./inventory_controller.js";
import AuthNavbarController from './auth_navbar_controller.js'
import StatsController from './stats_controller.js'


const application = Application.start();
application.register("auth", AuthController);
application.register("battle", BattleController);
application.register("inventory", InventoryController);
application.register('auth-navbar', AuthNavbarController)
application.register('stats', StatsController)

