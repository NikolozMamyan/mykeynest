import { Application } from "@hotwired/stimulus";
import BattleController from "./battle_controller.js";
import InventoryController from "./inventory_controller.js";



const application = Application.start();
application.register("battle", BattleController);
application.register("inventory", InventoryController);
